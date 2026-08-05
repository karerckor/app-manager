<?php

namespace App\Http\Controllers;

use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\Profile;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CredentialAttachmentController extends Controller
{
    public function store(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorize('manageCredentials', $team);
        abort_unless($credential->team_id === $team->id, 404);

        $rules = [
            'app_identifier_id' => ['required', 'uuid'],
            'profile_type' => CredentialSchema::requiresProfile($credential->type)
                ? ['required', Rule::enum(ProfileType::class)]
                : ['prohibited'],
        ];
        $data = $request->validate($rules);
        $identifier = AppIdentifier::query()
            ->whereKey($data['app_identifier_id'])
            ->whereHas('mobileApp', fn ($query) => $query->where('team_id', $team->id))
            ->firstOrFail();

        if (! CredentialSchema::supportsPlatform($credential->type, $identifier->platform->value)) {
            throw ValidationException::withMessages([
                'app_identifier_id' => 'This credential type cannot be attached to the selected platform.',
            ]);
        }

        if (CredentialSchema::requiresProfile($credential->type)) {
            $profileType = ProfileType::from($data['profile_type']);

            if (! BuildProfileSchema::supportsPlatform($profileType, $identifier->platform)) {
                throw ValidationException::withMessages([
                    'profile_type' => 'This build target cannot be used with the selected platform.',
                ]);
            }

            $definition = BuildProfileSchema::definition($profileType, $identifier->platform);
            $profile = $identifier->profiles()->firstOrCreate(
                ['type' => $profileType],
                ['name' => $definition['label']],
            );
            $profile->credentials()->syncWithoutDetaching([$credential->id]);
        } else {
            $identifier->credentials()->syncWithoutDetaching([$credential->id]);
        }

        return back()->with('status', 'Credential attached.');
    }

    public function destroy(
        Request $request,
        Team $team,
        Credential $credential,
        string $kind,
        string $attachment,
    ): RedirectResponse {
        $this->authorize('manageCredentials', $team);
        abort_unless($credential->team_id === $team->id, 404);

        if ($kind === 'identifier') {
            $identifier = AppIdentifier::query()
                ->whereKey($attachment)
                ->whereHas('mobileApp', fn ($query) => $query->where('team_id', $team->id))
                ->firstOrFail();
            abort_unless($credential->appIdentifiers()->whereKey($identifier->id)->exists(), 404);
            $identifier->credentials()->detach($credential->id);
        } elseif ($kind === 'profile') {
            $profile = Profile::query()
                ->whereKey($attachment)
                ->whereHas('appIdentifier.mobileApp', fn ($query) => $query->where('team_id', $team->id))
                ->firstOrFail();
            abort_unless($credential->profiles()->whereKey($profile->id)->exists(), 404);
            $profile->credentials()->detach($credential->id);
        } else {
            abort(404);
        }

        return back()->with('status', 'Credential detached.');
    }
}
