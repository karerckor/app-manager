<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Credentials\SyncVaultReference;
use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Enums\VaultMetadataSource;
use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Rules\ValidCredentialFile;
use App\Support\Audit\CredentialAccessAuditRecorder;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialFileStorage;
use App\Support\Credentials\CredentialRequestRules;
use App\Support\Credentials\CredentialSchema;
use App\Support\Credentials\CredentialUniqueness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CredentialController extends Controller
{
    use ResolvesPageSize;

    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly CredentialUniqueness $credentialUniqueness,
        private readonly ExpoAutoSyncDispatcher $expoAutoSync,
        private readonly CredentialAccessAuditRecorder $credentialAccessAudits,
        private readonly CredentialRequestRules $credentialRules,
        private readonly SyncVaultReference $vaultReferences,
    ) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $credentials = $team->credentials()
            ->where(fn ($query) => $query
                ->whereHas('appIdentifiers', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                ->orWhereHas('profiles.appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id)))
            ->with($this->relationsFor($mobileApp))
            ->latest()
            ->paginate($this->perPage($request));

        return CredentialResource::collection($credentials);
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $type = CredentialType::tryFrom((string) $request->input('type'));
        $mode = $this->credentialRules->resolveMode($team, $request);

        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => [
                'required',
                Rule::enum(CredentialType::class),
            ],
            'expires_at' => ['nullable', 'date'],
            ...$this->credentialRules->forCreate($team, $request, $mode, $type),
        ];

        if ($type) {
            $rules = [...$rules, ...CredentialSchema::attachmentRules($type)];
        }

        $data = $request->validate($rules);
        $data['storage_mode'] = $mode->value;
        $type = CredentialType::from($data['type']);
        $identifier = $mobileApp->appIdentifiers()->findOrFail($data['app_identifier_id']);
        $this->ensurePlatformIsSupported($type, $identifier);
        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
            : null;
        $this->ensureProfileIsSupported($profileType, $identifier);

        $credential = DB::transaction(function () use ($team, $data, $identifier, $profileType, $mobileApp, $request, $mode) {
            $credential = $team->credentials()->create([
                ...Arr::only($data, ['name', 'type', 'storage_mode', 'expires_at']),
                'payload' => $data['payload'] ?? null,
            ]);
            if ($profileType) {
                $profileDefinition = BuildProfileSchema::definition($profileType, $identifier->platform);
                $profile = $identifier->profiles()->firstOrCreate(
                    ['type' => $profileType],
                    ['name' => $profileDefinition['label']],
                );
                $profile->credentials()->attach($credential);
            } else {
                $identifier->credentials()->attach($credential);
            }

            if ($mode === CredentialStorageMode::External) {
                $this->vaultReferences->create($credential, $data['vault'], VaultMetadataSource::User);
            } else {
                CredentialFileStorage::storeUploaded($credential, $request->file('file'));
                $this->refreshFingerprint($credential);
            }

            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialCreated,
                $credential,
                WebhookPayload::credential($credential),
            );

            return $credential->load($this->relationsFor($mobileApp));
        });

        return (new CredentialResource($credential))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): CredentialResource {
        return new CredentialResource($this->credential($team, $mobileApp, $credential));
    }

    public function reveal(
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): JsonResponse {
        $credential = $this->credential($team, $mobileApp, $credential);
        $this->credentialAccessAudits->revealed($credential);

        return response()->json([
            'data' => [
                'id' => $credential->id,
                'payload' => (object) ($credential->payload ?? []),
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): CredentialResource {
        $credential = $this->credential($team, $mobileApp, $credential);
        $this->credentialRules->assertModeIsUnchanged($credential, $request);

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'type' => ['prohibited'],
            'app_identifier_id' => ['prohibited'],
            'profile_type' => ['prohibited'],
            ...$this->credentialRules->forPartialUpdate($credential, $request),
        ];

        $data = $request->validate($rules);
        DB::transaction(function () use ($credential, $data, $request, $team) {
            $credential->update(Arr::only($data, ['name', 'expires_at', 'payload']));

            if ($credential->isExternal()) {
                if ($request->has('vault')) {
                    $this->vaultReferences->update($credential->vaultReference, $data['vault'], VaultMetadataSource::User);
                }
            } else {
                if ($request->hasFile('file')) {
                    CredentialFileStorage::storeUploaded($credential, $request->file('file'));
                }

                $this->refreshFingerprint($credential);
            }

            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return new CredentialResource($credential->refresh()->load($this->relationsFor($mobileApp)));
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp, string $credential): Response
    {
        $credential = $this->credential($team, $mobileApp, $credential);
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

        return response()->noContent();
    }

    public function download(
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): StreamedResponse {
        $credentialModel = $this->credential($team, $mobileApp, $credential);
        $media = $credentialModel->getFirstMedia('file');
        abort_unless($media, 404);

        $this->credentialAccessAudits->downloaded($credentialModel, $media);

        return CredentialFileStorage::download($media);
    }

    public function upload(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): CredentialResource {
        $credential = $this->credential($team, $mobileApp, $credential);
        abort_if($credential->isExternal(), 404);
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                new ValidCredentialFile($credential->type, $credential->payload ?? []),
            ],
        ]);
        DB::transaction(function () use ($credential, $team, $request) {
            CredentialFileStorage::storeUploaded($credential, $request->file('file'));
            $this->refreshFingerprint($credential);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return new CredentialResource($credential->refresh()->load($this->relationsFor($mobileApp)));
    }

    public function destroyFile(Request $request, Team $team, MobileApp $mobileApp, string $credential): Response
    {
        $credential = $this->credential($team, $mobileApp, $credential);
        abort_if($credential->isExternal(), 404);
        abort_unless($credential->getFirstMedia('file'), 404);
        DB::transaction(function () use ($credential, $team) {
            $credential->clearMediaCollection('file');
            $this->refreshFingerprint($credential);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialUpdated,
                $credential,
                WebhookPayload::credential($credential),
            );
        });

        return response()->noContent();
    }

    private function credential(Team $team, MobileApp $mobileApp, string $id): Credential
    {
        return $team->credentials()
            ->whereKey($id)
            ->where(fn ($query) => $query
                ->whereHas('appIdentifiers', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                ->orWhereHas('profiles.appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id)))
            ->with($this->relationsFor($mobileApp))
            ->firstOrFail();
    }

    private function ensurePlatformIsSupported(CredentialType $type, AppIdentifier $identifier): void
    {
        if (! CredentialSchema::supportsPlatform($type, $identifier->platform->value)) {
            throw ValidationException::withMessages([
                'type' => 'This credential type cannot be attached to the selected platform.',
            ]);
        }
    }

    private function ensureProfileIsSupported(?ProfileType $type, AppIdentifier $identifier): void
    {
        if ($type && ! BuildProfileSchema::supportsPlatform($type, $identifier->platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }
    }

    private function relationsFor(MobileApp $mobileApp): array
    {
        return [
            'appIdentifiers' => fn ($query) => $query->where('mobile_app_id', $mobileApp->id),
            'profiles' => fn ($query) => $query
                ->whereHas('appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                ->with('appIdentifier'),
            'vaultReference',
        ];
    }

    private function refreshFingerprint(Credential $credential): void
    {
        $credential->forceFill([
            'fingerprint' => $this->credentialUniqueness->fingerprintCredential($credential),
        ])->saveQuietly();
    }
}
