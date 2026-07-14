<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Rules\ValidCredentialFile;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CredentialController extends Controller
{
    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        $type = CredentialType::tryFrom((string) $request->input('type'));
        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
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

        $identifier = AppIdentifier::findOrFail($data['app_identifier_id']);

        if (! CredentialSchema::supportsPlatform($type, $identifier->platform->value)) {
            throw ValidationException::withMessages([
                'type' => 'This credential type cannot be attached to the selected platform.',
            ]);
        }

        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
            : null;

        if ($profileType && ! BuildProfileSchema::supportsPlatform($profileType, $identifier->platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }

        $credential = $mobileApp->team->credentials()->create([
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

        if ($request->hasFile('file')) {
            $credential->addMediaFromRequest('file')->toMediaCollection('file');
        }

        return back()->with('status', 'Credential added.');
    }

    public function update(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

        $type = CredentialType::tryFrom((string) $request->input('type'));
        $mobileApp = $team->mobileApps()->findOrFail($request->input('mobile_app_id'));
        abort_unless($this->isAttachedToApp($credential, $mobileApp), 404);

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
            'mobile_app_id' => [
                'required',
                Rule::exists('mobile_apps', 'id')->where('team_id', $team->id),
            ],
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => ['nullable', 'date'],
        ];

        if ($type) {
            $fileRequired = $type !== $credential->type || ! $credential->getFirstMedia('file');
            $rules = [
                ...$rules,
                ...CredentialSchema::rules($type, $fileRequired, (array) $request->input('payload', [])),
                ...CredentialSchema::attachmentRules($type),
            ];
        }

        $data = $request->validate($rules);
        $type = CredentialType::from($data['type']);
        $identifier = $mobileApp->appIdentifiers()->findOrFail($data['app_identifier_id']);

        if (! CredentialSchema::supportsPlatform($type, $identifier->platform->value)) {
            throw ValidationException::withMessages([
                'type' => 'This credential type cannot be attached to the selected platform.',
            ]);
        }

        $profileType = isset($data['profile_type'])
            ? ProfileType::from($data['profile_type'])
            : null;

        if ($profileType && ! BuildProfileSchema::supportsPlatform($profileType, $identifier->platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }

        if ($type !== $credential->type && $this->isAttachedOutsideApp($credential, $mobileApp)) {
            throw ValidationException::withMessages([
                'type' => 'A shared credential cannot change type until it is detached from its other applications.',
            ]);
        }

        DB::transaction(function () use ($credential, $data, $type, $mobileApp, $identifier, $profileType, $request) {
            $credential->update([
                ...Arr::only($data, ['name', 'expires_at']),
                'type' => $type,
                'payload' => $data['payload'] ?? null,
            ]);

            $this->detachFromApp($credential, $mobileApp);

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

            if ($request->hasFile('file')) {
                $credential->addMediaFromRequest('file')->toMediaCollection('file');
            }
        });

        return back()->with('status', 'Credential updated.');
    }

    public function upload(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($credential->team_id === $team->id, 404);
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                new ValidCredentialFile($credential->type, $credential->payload ?? []),
            ],
        ]);
        $credential->addMediaFromRequest('file')->toMediaCollection('file');

        return back()->with('status', 'Credential file updated.');
    }

    public function download(Request $request, Team $team, Credential $credential): BinaryFileResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($credential->team_id === $team->id, 404);
        $media = $credential->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }

    private function isAttachedToApp(Credential $credential, MobileApp $mobileApp): bool
    {
        return $credential->appIdentifiers()
            ->where('app_identifiers.mobile_app_id', $mobileApp->id)
            ->exists()
            || $credential->profiles()
                ->whereHas('appIdentifier', fn ($query) => $query->where('mobile_app_id', $mobileApp->id))
                ->exists();
    }

    private function isAttachedOutsideApp(Credential $credential, MobileApp $mobileApp): bool
    {
        return $credential->appIdentifiers()
            ->where('app_identifiers.mobile_app_id', '!=', $mobileApp->id)
            ->exists()
            || $credential->profiles()
                ->whereHas('appIdentifier', fn ($query) => $query->where('mobile_app_id', '!=', $mobileApp->id))
                ->exists();
    }

    private function detachFromApp(Credential $credential, MobileApp $mobileApp): void
    {
        $mobileApp->appIdentifiers()
            ->with('profiles')
            ->get()
            ->each(function (AppIdentifier $identifier) use ($credential) {
                $identifier->credentials()->detach($credential->id);
                $identifier->profiles->each(
                    fn ($profile) => $profile->credentials()->detach($credential->id),
                );
            });
    }
}
