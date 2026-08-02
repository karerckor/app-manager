<?php

namespace App\Http\Controllers;

use App\Actions\Builds\ImportEasBuilds;
use App\Enums\IntegrationStatus;
use App\Enums\TeamPermission;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpoBuildController extends Controller
{
    public function cancel(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $buildId,
        ExpoClient $expo,
    ): RedirectResponse {
        [$project, $remote] = $this->context($request, $team, $mobileApp, $expo);
        $build = collect((array) ($remote['builds'] ?? []))
            ->firstWhere('id', $buildId);
        abort_unless(is_array($build), 404);

        try {
            $expo->cancelBuild($project->integration, $buildId);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Expo build cancellation requested.');
    }

    public function retry(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $buildId,
        ExpoClient $expo,
    ): RedirectResponse {
        [$project, $remote] = $this->context($request, $team, $mobileApp, $expo);
        $build = collect((array) ($remote['builds'] ?? []))
            ->firstWhere('id', $buildId);
        abort_unless(is_array($build), 404);

        try {
            $expo->retryBuild($project->integration, $buildId, (string) $build['platform']);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Expo build retry started.');
    }

    public function importBuilds(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ImportEasBuilds $import,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);

        $data = $request->validate([
            'build_ids' => ['required', 'array', 'min:1', 'max:50'],
            'build_ids.*' => ['required', 'string'],
        ]);

        $imported = $import->handle(
            $team,
            $mobileApp,
            $request->user(),
            $data['build_ids'],
        );

        $count = count($imported);

        return back()->with(
            'status',
            $count === 1
                ? '1 EAS build imported successfully.'
                : "{$count} EAS builds imported successfully.",
        );
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
    }

    private function authorize(Request $request, Team $team, TeamPermission $permission): void
    {
        abort_unless($team->allows($request->user(), $permission), 403);
    }

    /** @return array{0: ExpoProject, 1: array<string, mixed>} */
    private function context(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): array {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
        abort_unless($team->allows($request->user(), TeamPermission::RunPipelines), 403);
        $project = $mobileApp->expoProject()->with('integration')->firstOrFail();
        abort_unless(
            $project->team_id === $team->id
            && $project->integration->status === IntegrationStatus::Active,
            404,
        );

        try {
            $remote = $expo->project($project->integration, $project->external_id);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return [$project, $remote];
    }
}
