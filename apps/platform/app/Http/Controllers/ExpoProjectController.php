<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\ImportExpoProject;
use App\Actions\Integrations\SyncExpoProjects;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamPermission;
use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Jobs\Integrations\ImportExpoProject as ImportExpoProjectJob;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExpoProjectController extends Controller
{
    public function setup(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
        ExpoCredentialExporter $credentials,
        SyncExpoProjects $sync,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        abort_if(
            $mobileApp->expoProject()
                ->whereHas('integration', fn ($query) => $query->connectedExpo())
                ->exists(),
            409,
            'This application is already linked to an available Expo account.',
        );
        $replacingInactiveLink = $mobileApp->expoProject()->exists();

        $data = $request->validate([
            'integration_id' => [
                'required',
                Rule::exists('integrations', 'id')
                    ->where('team_id', $team->id)
                    ->where('provider', IntegrationProvider::Expo->value)
                    ->where('status', IntegrationStatus::Active->value),
            ],
            'mode' => ['required', Rule::in(['create', 'existing'])],
            'expo_project_id' => [
                Rule::requiredIf($request->input('mode') === 'existing'),
                'nullable',
                'uuid',
            ],
            'sync_metadata' => ['sometimes', 'boolean'],
        ]);
        $integration = $team->integrations()->findOrFail($data['integration_id']);

        if ($data['mode'] === 'create') {
            $this->authorize($request, $team, TeamPermission::ManageCredentials);

            try {
                $remote = $expo->createProject($integration, $mobileApp->slug, $mobileApp->name);
            } catch (ExpoGraphqlException $exception) {
                throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
            }

            $project = ExpoProject::query()->updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'external_id' => (string) $remote['id'],
                ],
                $sync->attributes($integration, $remote),
            );
        } else {
            $project = $integration->expoProjects()
                ->where('exists_on_expo', true)
                ->findOrFail($data['expo_project_id']);
            if ($project->mobile_app_id) {
                throw ValidationException::withMessages([
                    'expo_project_id' => 'That Expo project is already linked to an application.',
                ]);
            }
        }

        DB::transaction(function () use ($project, $mobileApp, $data): void {
            $inactiveProject = $mobileApp->expoProject()->lockForUpdate()->first();

            if ($inactiveProject && ! $inactiveProject->is($project)) {
                $mobileApp->ciCdPipelines()
                    ->where('integration_id', $inactiveProject->integration_id)
                    ->where('repository_id', $inactiveProject->external_id)
                    ->update(['enabled' => false]);
                $inactiveProject->update([
                    'mobile_app_id' => null,
                    'auto_sync_enabled' => false,
                    'auto_sync_status' => 'idle',
                    'auto_sync_pending_at' => null,
                ]);
            }

            $project->update([
                'mobile_app_id' => $mobileApp->id,
                'sync_metadata' => (bool) ($data['sync_metadata'] ?? true),
            ]);
            if ($project->sync_metadata) {
                $mobileApp->update([
                    'name' => $project->name,
                    'description' => $project->description,
                ]);
            }
        });

        if ($data['mode'] === 'create') {
            $export = $credentials->exportApplication($project, $mobileApp);

            if ($export['errors'] !== []) {
                throw ValidationException::withMessages([
                    'expo' => 'The Expo project was created and linked, but some identifiers or '
                        .'credentials could not be uploaded. '
                        .implode(' ', $export['errors']),
                ]);
            }

            return redirect()
                ->route('accounts.apps.expo.index', [$team, $mobileApp])
                ->with(
                    'status',
                    "Application set up on Expo with {$export['identifiers']} identifiers and "
                    ."{$export['credentials']} credentials."
                    .($replacingInactiveLink
                        ? ' The previous local Expo link was replaced; nothing was deleted on Expo.'
                        : ''),
                );
        }

        return redirect()
            ->route('accounts.apps.expo.index', [$team, $mobileApp])
            ->with(
                'status',
                $replacingInactiveLink
                    ? 'Application set up on Expo. The previous local Expo link was replaced; nothing was deleted on Expo.'
                    : 'Application set up on Expo.',
            );
    }

    public function import(
        Request $request,
        Team $team,
        Integration $integration,
        ExpoProject $expoProject,
        ExpoClient $expo,
        ImportExpoProject $importer,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        $this->authorize($request, $team, TeamPermission::ManageCredentials);
        $this->assertProject($team, $integration, $expoProject);
        abort_if($expoProject->mobile_app_id, 409, 'This Expo project is already linked.');

        try {
            $remote = $expo->projectWithCredentialSecrets($integration, $expoProject->external_id);
            $result = $importer->handle($team, $expoProject, $remote);
        } catch (ExpoGraphqlException|RuntimeException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        $app = $result['app'];

        return redirect()
            ->route('accounts.apps.expo.index', [$team, $app])
            ->with(
                'status',
                "Expo project imported with {$result['identifiers']} identifiers and "
                ."{$result['credentials']} credentials.",
            );
    }

    public function importBatch(
        Request $request,
        Team $team,
        Integration $integration,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        $this->authorize($request, $team, TeamPermission::ManageCredentials);
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo
            && in_array($integration->status, [IntegrationStatus::Active, IntegrationStatus::Degraded], true)
            && $integration->oauthCredential()->exists(),
            404,
        );

        $data = $request->validate([
            'project_ids' => ['required', 'array', 'min:1', 'max:100'],
            'project_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $projects = $integration->expoProjects()
            ->where('team_id', $team->id)
            ->where('exists_on_expo', true)
            ->whereNull('mobile_app_id')
            ->whereIn('id', $data['project_ids'])
            ->pluck('id');

        if ($projects->count() !== count($data['project_ids'])) {
            throw ValidationException::withMessages([
                'project_ids' => 'One or more selected Expo projects are unavailable for import.',
            ]);
        }

        DB::afterCommit(fn () => $projects->each(
            fn (string $projectId) => ImportExpoProjectJob::dispatch($team->id, $projectId)
                ->onQueue('integrations'),
        ));

        return back()->with('status', "{$projects->count()} Expo project(s) queued for import.");
    }

    public function sync(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
        SyncExpoProjects $sync,
        ImportExpoProject $importer,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        $this->authorize($request, $team, TeamPermission::ManageCredentials);
        $project = $mobileApp->expoProject()->with('integration')->firstOrFail();
        abort_unless($project->team_id === $team->id, 404);

        try {
            $remote = $expo->projectWithCredentialSecrets(
                $project->integration,
                $project->external_id,
            );
            $importer->handle($team, $project, $remote, $mobileApp);
        } catch (ExpoGraphqlException|RuntimeException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($project, $remote, $sync, $mobileApp): void {
            $project->update($sync->attributes($project->integration, $remote));
            if ($project->sync_metadata) {
                $mobileApp->update([
                    'name' => $project->name,
                    'description' => $project->description,
                ]);
            }
        });

        return back()->with('status', 'Expo project synced.');
    }

    public function updateAutomaticSync(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoAutoSyncDispatcher $autoSync,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        $this->authorize($request, $team, TeamPermission::ManageCredentials);
        $project = $mobileApp->expoProject()->with('integration')->firstOrFail();
        abort_unless($project->team_id === $team->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        if ($enabled && (! $project->exists_on_expo || ! $project->integration->status->canDispatch())) {
            throw ValidationException::withMessages([
                'enabled' => 'Reconnect the Expo account before enabling automatic sync.',
            ]);
        }

        $project->update([
            'auto_sync_enabled' => $enabled,
            'auto_sync_status' => $enabled ? 'queued' : 'idle',
            'auto_sync_error' => null,
            'auto_sync_pending_at' => $enabled ? now() : null,
        ]);

        if ($enabled) {
            $autoSync->dispatchForAppIds([$mobileApp->id]);
        }

        return back()->with(
            'status',
            $enabled
                ? 'Automatic Expo sync enabled. The first push was queued.'
                : 'Automatic Expo sync disabled.',
        );
    }

    public function unlink(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
    ): RedirectResponse {
        $this->authorize($request, $team, TeamPermission::ManageApplications);
        $project = $mobileApp->expoProject()->firstOrFail();
        abort_unless($project->team_id === $team->id, 404);
        DB::transaction(function () use ($project, $mobileApp): void {
            $mobileApp->ciCdPipelines()
                ->where('integration_id', $project->integration_id)
                ->where('repository_id', $project->external_id)
                ->update(['enabled' => false]);
            $project->update([
                'mobile_app_id' => null,
                'auto_sync_enabled' => false,
                'auto_sync_status' => 'idle',
                'auto_sync_pending_at' => null,
            ]);
        });

        return back()->with(
            'status',
            'Expo project unlinked and its EAS pipelines disabled. Nothing was deleted on Expo.',
        );
    }

    private function assertProject(
        Team $team,
        Integration $integration,
        ExpoProject $project,
    ): void {
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo
            && $project->integration_id === $integration->id
            && $project->team_id === $team->id,
            404,
        );
    }

    private function authorize(Request $request, Team $team, TeamPermission $permission): void
    {
        abort_unless($team->allows($request->user(), $permission), 403);
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
    }
}
