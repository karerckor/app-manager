<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Rules\ValidCredentialFile;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CredentialController extends Controller
{
    public function index(Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $credentials = $team->credentials()
            ->where(fn ($query) => $query
                ->whereHas('appIdentifiers', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                ->orWhereHas('profiles.appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id)))
            ->with($this->relationsFor($mobileApp))
            ->latest()
            ->get();

        return CredentialResource::collection($credentials);
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->authorize('manageCredentials', $team);

        $type = CredentialType::tryFrom((string) $request->input('type'));
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
        $identifier = $mobileApp->appIdentifiers()->findOrFail($data['app_identifier_id']);
        $this->ensurePlatformIsSupported($type, $identifier);
        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
            : null;
        $this->ensureProfileIsSupported($profileType, $identifier);

        $credential = $team->credentials()->create([
            ...Arr::only($data, ['name', 'type', 'expires_at']),
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
        $credential->addMediaFromRequest('file')->toMediaCollection('file');
        $credential->load($this->relationsFor($mobileApp));

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

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): CredentialResource {
        $this->authorize('manageCredentials', $team);

        $credential = $this->credential($team, $mobileApp, $credential);
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'type' => ['prohibited'],
            'app_identifier_id' => ['prohibited'],
            'profile_type' => ['prohibited'],
        ];

        if ($request->has('payload')) {
            $rules = [
                ...$rules,
                ...CredentialSchema::rules(
                    $credential->type,
                    false,
                    (array) $request->input('payload', []),
                ),
            ];
        } elseif ($request->hasFile('file')) {
            $rules['file'] = [
                'file',
                'max:20480',
                new ValidCredentialFile($credential->type, $credential->payload ?? []),
            ];
        }

        $data = $request->validate($rules);
        $credential->update(Arr::only($data, ['name', 'expires_at', 'payload']));

        if ($request->hasFile('file')) {
            $credential->addMediaFromRequest('file')->toMediaCollection('file');
        }

        return new CredentialResource($credential->refresh()->load($this->relationsFor($mobileApp)));
    }

    public function destroy(Team $team, MobileApp $mobileApp, string $credential): Response
    {
        $this->authorize('manageCredentials', $team);

        $this->credential($team, $mobileApp, $credential)->delete();

        return response()->noContent();
    }

    public function download(
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): BinaryFileResponse {
        $media = $this->credential($team, $mobileApp, $credential)->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    public function upload(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): CredentialResource {
        $this->authorize('manageCredentials', $team);

        $credential = $this->credential($team, $mobileApp, $credential);
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                new ValidCredentialFile($credential->type, $credential->payload ?? []),
            ],
        ]);
        $credential->addMediaFromRequest('file')->toMediaCollection('file');

        return new CredentialResource($credential->refresh()->load($this->relationsFor($mobileApp)));
    }

    public function destroyFile(Team $team, MobileApp $mobileApp, string $credential): Response
    {
        $this->authorize('manageCredentials', $team);

        $credential = $this->credential($team, $mobileApp, $credential);
        abort_unless($credential->getFirstMedia('file'), 404);
        $credential->clearMediaCollection('file');

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
        ];
    }
}
