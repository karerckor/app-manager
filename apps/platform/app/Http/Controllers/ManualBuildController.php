<?php

namespace App\Http\Controllers;

use App\Actions\Builds\CreateManualBuild;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\AppIdentifierResolver;
use App\Support\Credentials\BuildProfileSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualBuildController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        CreateManualBuild $create,
        AppIdentifierResolver $identifiers,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('runPipelines', $team);
        abort_unless($mobileApp->team_id === $team->id, 404);

        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'app_identifier_id' => ['nullable', 'uuid'],
            'platform' => ['sometimes', Rule::enum(Platform::class)],
            'parameters' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 65535) {
                        $fail('Build parameters may not exceed 64 KB.');
                    }
                },
            ],
        ]);
        $identifier = isset($data['app_identifier_id'])
            ? AppIdentifier::query()
                ->whereKey($data['app_identifier_id'])
                ->where('mobile_app_id', $mobileApp->id)
                ->firstOrFail()
            : null;
        $platform = isset($data['platform']) ? Platform::from($data['platform']) : null;

        if ($identifier && $platform && $identifier->platform !== $platform) {
            throw ValidationException::withMessages([
                'platform' => 'The selected identifier does not belong to this platform.',
            ]);
        }

        if ($identifier === null && $platform) {
            $identifier = $identifiers->resolve($mobileApp, $platform);
        }

        if ($platform && ! BuildProfileSchema::supportsPlatform(ProfileType::from($data['profile_type']), $platform)) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }
        $build = $create->handle(
            $team,
            $mobileApp,
            $request->user(),
            $data['request_uuid'],
            ProfileType::from($data['profile_type']),
            $identifier,
            parameters: $data['parameters'] ?? [],
            source: 'web',
        );

        return redirect()
            ->route('accounts.apps.builds.index', [$team, $mobileApp])
            ->with('status', 'Manual build created.')
            ->with('build_id', $build->id);
    }
}
