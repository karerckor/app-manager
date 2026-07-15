<?php

namespace App\Http\Controllers;

use App\Actions\Credentials\SyncVaultReference;
use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Http\Requests\AttachCredentialRequest;
use App\Http\Requests\DetachCredentialRequest;
use App\Http\Requests\UploadCredentialFileRequest;
use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Audit\CredentialAccessAuditRecorder;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialFileStorage;
use App\Support\Credentials\CredentialRequestRules;
use App\Support\Credentials\CredentialSchema;
use App\Support\Credentials\CredentialUniqueness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CredentialController extends Controller
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly CredentialUniqueness $credentialUniqueness,
        private readonly ExpoAutoSyncDispatcher $expoAutoSync,
        private readonly CredentialAccessAuditRecorder $credentialAccessAudits,
        private readonly CredentialRequestRules $credentialRules,
        private readonly SyncVaultReference $vaultReferences,
    ) {}

    public function attach(
        AttachCredentialRequest $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        $credential = $team->credentials()->findOrFail($request->validated('credential_id'));

        $profileType = $request->has('profile_type')
            ? ProfileType::from($request->validated('profile_type'))
            : null;

        DB::transaction(function () use ($credential, $appIdentifier, $profileType, $team) {
            if ($profileType) {
                $profileDefinition = BuildProfileSchema::definition($profileType, $appIdentifier->platform);
                $profile = $appIdentifier->profiles()->firstOrCreate(
                    ['type' => $profileType],
                    ['name' => $profileDefinition['label']],
                );
                $profile->credentials()->syncWithoutDetaching([$credential->id]);
            } else {
                $appIdentifier->credentials()->syncWithoutDetaching([$credential->id]);
            }

            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return back()->with('status', 'Credential reused from Credentials Vault.');
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $type = CredentialType::tryFrom((string) $request->input('type'));
        $mode = $this->credentialRules->resolveMode($team, $request);

        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => $type && CredentialSchema::expires($type)
                ? ['nullable', 'date']
                : ['prohibited'],
            'check_uniqueness' => ['sometimes', 'boolean'],
            ...$this->credentialRules->forCreate($team, $request, $mode, $type),
        ];

        if ($type) {
            $rules = [...$rules, ...CredentialSchema::attachmentRules($type)];
        }

        $data = $request->validate($rules);
        $data['storage_mode'] = $mode->value;
        $type = CredentialType::from($data['type']);
        $identifier = AppIdentifier::findOrFail($data['app_identifier_id']);

        if (! CredentialSchema::supportsPlatform($type, $identifier->platform->value)) {
            throw ValidationException::withMessages([
                'type' => 'This credential type cannot be attached to the selected platform.',
            ]);
        }

        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
            : null;
        $checkUniqueness = array_key_exists('check_uniqueness', $data)
            ? (bool) $data['check_uniqueness']
            : $team->check_credential_uniqueness_by_default;

        if ($profileType && ! BuildProfileSchema::supportsPlatform($profileType, $identifier->platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }

        $fingerprint = $mode === CredentialStorageMode::External
            ? null
            : $this->credentialUniqueness->fingerprintUpload(
                $type,
                $data['payload'] ?? [],
                $request->file('file'),
            );

        $reused = DB::transaction(function () use (
            $mobileApp,
            $team,
            $request,
            $data,
            $type,
            $identifier,
            $profileType,
            $checkUniqueness,
            $fingerprint,
            $mode,
        ) {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            $credential = $checkUniqueness
                ? ($mode === CredentialStorageMode::External
                    ? $this->credentialUniqueness->findMatchingVaultReference(
                        $team,
                        $type,
                        VaultProvider::from($data['vault']['provider']),
                        $data['vault']['resource'],
                    )
                    : $this->credentialUniqueness->findMatching($team, $type, $fingerprint))
                : null;
            $reused = $credential !== null;

            if (! $credential) {
                $credential = $mobileApp->team->credentials()->create([
                    ...Arr::only($data, ['name', 'type', 'storage_mode', 'expires_at']),
                    'payload' => $data['payload'] ?? null,
                    'fingerprint' => $fingerprint,
                ]);

                if ($mode === CredentialStorageMode::External) {
                    $this->vaultReferences->create(
                        $credential,
                        $data['vault'],
                        VaultMetadataSource::User,
                    );
                }
            }

            if ($profileType) {
                $profileDefinition = BuildProfileSchema::definition($profileType, $identifier->platform);
                $profile = $identifier->profiles()->firstOrCreate(
                    ['type' => $profileType],
                    ['name' => $profileDefinition['label']],
                );
                $profile->credentials()->syncWithoutDetaching([$credential->id]);
            } else {
                $identifier->credentials()->syncWithoutDetaching([$credential->id]);
            }

            if (! $reused && $mode === CredentialStorageMode::Local && $request->hasFile('file')) {
                CredentialFileStorage::storeUploaded($credential, $request->file('file'));
            }

            $this->webhookEvents->record(
                $team,
                $reused
                    ? WebhookEventType::CredentialUpdated
                    : WebhookEventType::CredentialCreated,
                $credential,
                WebhookPayload::credential($credential),
            );

            return $reused;
        });

        return back()->with(
            'status',
            $reused
                ? 'Duplicate found. Existing credential reused from Credentials Vault.'
                : 'Credential added.',
        );
    }

    public function detach(
        DetachCredentialRequest $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
        string $credential,
    ): RedirectResponse {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($appIdentifier->mobile_app_id === $mobileApp->id, 404);
        $credential = $team->credentials()->findOrFail($credential);

        $profileType = $request->has('profile_type')
            ? ProfileType::from($request->validated('profile_type'))
            : null;

        DB::transaction(function () use ($credential, $appIdentifier, $profileType, $team) {
            if ($profileType) {
                $profile = $appIdentifier->profiles()
                    ->where('type', $profileType)
                    ->firstOrFail();
                abort_unless($profile->credentials()->whereKey($credential->id)->exists(), 404);
                $profile->credentials()->detach($credential->id);
            } else {
                abort_unless($appIdentifier->credentials()->whereKey($credential->id)->exists(), 404);
                $appIdentifier->credentials()->detach($credential->id);
            }

            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });
        $this->expoAutoSync->dispatchForAppIds([$mobileApp->id]);

        return back()->with('status', 'Credential detached. It remains in Credentials Vault.');
    }

    public function update(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->credentialRules->assertModeIsUnchanged($credential, $request);

        $type = CredentialType::tryFrom((string) $request->input('type'));

        if ($type
            && $type !== $credential->type
            && ($credential->appIdentifiers()->exists() || $credential->profiles()->exists())) {
            throw ValidationException::withMessages([
                'type' => 'An attached credential cannot change type. Detach it from all targets first.',
            ]);
        }

        if ($type === $credential->type && ! $credential->isExternal()) {
            $payload = (array) $request->input('payload', []);

            foreach (CredentialSchema::definition($type)['fields'] as $field) {
                if ($field['input'] === 'password'
                    && blank($payload[$field['name']] ?? null)
                    && array_key_exists($field['name'], $credential->payload ?? [])) {
                    $payload[$field['name']] = $credential->payload[$field['name']];
                }
            }

            $request->merge(['payload' => $payload]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => $type && CredentialSchema::expires($type)
                ? ['nullable', 'date']
                : ['prohibited'],
            ...$this->credentialRules->forUpdate($credential, $request, $type),
        ];

        $data = $request->validate($rules);
        $type = CredentialType::from($data['type']);

        DB::transaction(function () use ($credential, $data, $type, $request, $team) {
            $credential->update([
                ...Arr::only($data, ['name']),
                'type' => $type,
                'expires_at' => CredentialSchema::expires($type) ? ($data['expires_at'] ?? null) : null,
                'payload' => $data['payload'] ?? null,
            ]);

            if ($credential->isExternal()) {
                $this->vaultReferences->update(
                    $credential->vaultReference,
                    $data['vault'],
                    VaultMetadataSource::User,
                );
            } else {
                if ($request->hasFile('file')) {
                    CredentialFileStorage::storeUploaded($credential, $request->file('file'));
                }

                $credential->forceFill([
                    'fingerprint' => $this->credentialUniqueness->fingerprintCredential($credential),
                ])->saveQuietly();
            }

            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return back()->with('status', 'Credential updated.');
    }

    public function destroy(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $appIds = $this->expoAutoSync->appIdsForCredential($credential);

        DB::transaction(function () use ($credential, $team) {
            $payload = WebhookPayload::credential($credential);
            $credential->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialDeleted,
                $credential,
                $payload,
            );
        });
        $this->expoAutoSync->dispatchForAppIds($appIds);

        return back()->with('status', 'Credential removed.');
    }

    public function upload(UploadCredentialFileRequest $request, Team $team, Credential $credential): RedirectResponse
    {
        abort_if($credential->isExternal(), 404);

        DB::transaction(function () use ($credential, $team, $request) {
            CredentialFileStorage::storeUploaded($credential, $request->file('file'));
            $credential->forceFill([
                'fingerprint' => $this->credentialUniqueness->fingerprintCredential($credential),
            ])->saveQuietly();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return back()->with('status', 'Credential file updated.');
    }

    public function download(Request $request, Team $team, Credential $credential): StreamedResponse
    {
        $media = $credential->getFirstMedia('file');
        abort_unless($media, 404);
        $this->credentialAccessAudits->downloaded($credential, $media);

        return CredentialFileStorage::download($media);
    }

    public function reveal(Request $request, Team $team, Credential $credential): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'current_password:web'],
            'scope' => ['sometimes', Rule::in(['passwords', 'all'])],
        ]);

        if ($validator->fails()) {
            return response()
                ->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                ], 422)
                ->header('Cache-Control', 'no-store, private');
        }

        $payload = $credential->payload ?? [];
        $revealAll = $request->string('scope')->toString() === 'all';
        $secrets = collect(CredentialSchema::definition($credential->type)['fields'])
            ->filter(fn (array $field) => ($revealAll || $field['input'] === 'password')
                && array_key_exists($field['name'], $payload))
            ->map(fn (array $field) => [
                'name' => $field['name'],
                'label' => $field['label'],
                'value' => (string) $payload[$field['name']],
            ])
            ->values()
            ->all();

        abort_if($secrets === [], 404);

        $request->session()->put('auth.password_confirmed_at', now()->timestamp);
        $this->credentialAccessAudits->revealed($credential);

        return response()
            ->json(['secrets' => $secrets])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
