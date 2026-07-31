<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Enums\TeamPermission;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Rules\ValidCredentialFile;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use App\Support\Credentials\CredentialUniqueness;
use App\Support\Storage\MediaFileResponse;
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
    ) {}

    public function attach(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        $this->authorizeManagement($request, $team);

        $data = $request->validate([
            'credential_id' => [
                'required',
                Rule::exists('credentials', 'id')->where('team_id', $team->id),
            ],
        ]);
        $credential = $team->credentials()->findOrFail($data['credential_id']);

        if (! CredentialSchema::supportsPlatform($credential->type, $appIdentifier->platform->value)) {
            throw ValidationException::withMessages([
                'credential_id' => 'This credential cannot be attached to the selected platform.',
            ]);
        }

        $profileData = $request->validate([
            'profile_type' => CredentialSchema::requiresProfile($credential->type)
                ? ['required', Rule::enum(ProfileType::class)]
                : ['prohibited'],
        ]);
        $profileType = isset($profileData['profile_type'])
            ? ProfileType::from($profileData['profile_type'])
            : null;

        if ($profileType && ! BuildProfileSchema::supportsPlatform($profileType, $appIdentifier->platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }

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
        $this->authorizeManagement($request, $team);

        $type = CredentialType::tryFrom((string) $request->input('type'));
        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => ['nullable', 'date'],
            'check_uniqueness' => ['sometimes', 'boolean'],
        ];

        if ($type) {
            $rules = [
                ...$rules,
                ...CredentialSchema::rules($type, true, (array) $request->input('payload', [])),
                ...CredentialSchema::attachmentRules($type),
            ];
        }

        $data = $request->validate($rules);
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

        $fingerprint = $this->credentialUniqueness->fingerprintUpload(
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
        ) {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            $credential = $checkUniqueness
                ? $this->credentialUniqueness->findMatching($team, $type, $fingerprint)
                : null;
            $reused = $credential !== null;

            if (! $credential) {
                $credential = $mobileApp->team->credentials()->create([
                    ...Arr::only($data, ['name', 'type', 'expires_at']),
                    'payload' => $data['payload'] ?? null,
                    'fingerprint' => $fingerprint,
                ]);
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

            if (! $reused && $request->hasFile('file')) {
                $credential->addMediaFromRequest('file')->toMediaCollection('file');
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
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
        Credential $credential,
    ): RedirectResponse {
        $this->authorizeManagement($request, $team);
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($appIdentifier->mobile_app_id === $mobileApp->id, 404);
        abort_unless($credential->team_id === $team->id, 404);

        $data = $request->validate([
            'profile_type' => ['nullable', Rule::enum(ProfileType::class)],
        ]);
        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
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

        return back()->with('status', 'Credential detached. It remains in Credentials Vault.');
    }

    public function update(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeManagement($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

        $type = CredentialType::tryFrom((string) $request->input('type'));

        if ($type
            && $type !== $credential->type
            && ($credential->appIdentifiers()->exists() || $credential->profiles()->exists())) {
            throw ValidationException::withMessages([
                'type' => 'An attached credential cannot change type. Detach it from all targets first.',
            ]);
        }

        if ($type === $credential->type) {
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
            'expires_at' => ['nullable', 'date'],
        ];

        if ($type) {
            $fileRequired = $type !== $credential->type || ! $credential->getFirstMedia('file');
            $rules = [
                ...$rules,
                ...CredentialSchema::rules($type, $fileRequired, (array) $request->input('payload', [])),
            ];
        }

        $data = $request->validate($rules);
        $type = CredentialType::from($data['type']);

        DB::transaction(function () use ($credential, $data, $type, $request, $team) {
            $credential->update([
                ...Arr::only($data, ['name', 'expires_at']),
                'type' => $type,
                'payload' => $data['payload'] ?? null,
            ]);

            if ($request->hasFile('file')) {
                $credential->addMediaFromRequest('file')->toMediaCollection('file');
            }

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

        return back()->with('status', 'Credential updated.');
    }

    public function destroy(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeManagement($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

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

        return back()->with('status', 'Credential removed.');
    }

    public function upload(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeManagement($request, $team);
        abort_unless($credential->team_id === $team->id, 404);
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                new ValidCredentialFile($credential->type, $credential->payload ?? []),
            ],
        ]);
        DB::transaction(function () use ($credential, $team) {
            $credential->addMediaFromRequest('file')->toMediaCollection('file');
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
        $this->authorizeManagement($request, $team);
        abort_unless($credential->team_id === $team->id, 404);
        $media = $credential->getFirstMedia('file');
        abort_unless($media, 404);

        return MediaFileResponse::download($media);
    }

    public function reveal(Request $request, Team $team, Credential $credential): JsonResponse
    {
        $this->authorizeManagement($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

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

        return response()
            ->json(['secrets' => $secrets])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function authorizeManagement(Request $request, Team $team): void
    {
        abort_unless($team->allows($request->user(), TeamPermission::ManageCredentials), 403);
    }
}
