<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\ImportExpoProject;
use App\Actions\Integrations\SyncExpoProjects;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpoProjectResource;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class ExpoProjectController extends Controller
{
    public function show(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): JsonResponse {
        $this->ensureEnabled();
        $data = $request->validate([
            'build_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'build_offset' => ['sometimes', 'integer', 'min:0'],
            'submission_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'submission_offset' => ['sometimes', 'integer', 'min:0'],
        ]);
        $project = $this->linkedProject($team, $mobileApp);

        try {
            $remote = $expo->project(
                $project->integration,
                $project->external_id,
                buildLimit: (int) ($data['build_limit'] ?? 30),
                buildOffset: (int) ($data['build_offset'] ?? 0),
                submissionLimit: (int) ($data['submission_limit'] ?? 30),
                submissionOffset: (int) ($data['submission_offset'] ?? 0),
            );
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        }

        return response()->json([
            'data' => [
                'project' => (new ExpoProjectResource($project))->resolve($request),
                'remote' => $remote,
            ],
        ]);
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
        ExpoCredentialExporter $credentials,
        SyncExpoProjects $sync,
    ): JsonResponse {
        $this->ensureEnabled();
        abort_if($mobileApp->expoProject()->exists(), 409, 'This application is already linked to Expo.');

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
        $integration = $team->integrations()
            ->whereHas('oauthCredential')
            ->findOrFail($data['integration_id']);
        $createdOnExpo = $data['mode'] === 'create';

        if ($createdOnExpo) {
            try {
                $remote = $expo->createProject($integration, $mobileApp->slug, $mobileApp->name);
            } catch (ExpoGraphqlException $exception) {
                return $this->upstreamError($exception);
            }
            if (blank($remote['id'] ?? null)) {
                return $this->operationError('Expo did not return the created project.', 502);
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
                ->where('team_id', $team->id)
                ->where('exists_on_expo', true)
                ->findOrFail($data['expo_project_id']);
            abort_if($project->mobile_app_id, 409, 'This Expo project is already linked.');
        }

        DB::transaction(function () use ($project, $mobileApp, $data): void {
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

        $export = ['identifiers' => 0, 'credentials' => 0, 'errors' => []];
        if ($createdOnExpo) {
            $export = $credentials->exportApplication($project, $mobileApp);
        }

        return response()->json([
            'data' => (new ExpoProjectResource(
                $project->refresh()->load('mobileApp'),
            ))->resolve($request),
            'meta' => [
                'created_on_expo' => $createdOnExpo,
                'identifiers_pushed' => $export['identifiers'],
                'credentials_pushed' => $export['credentials'],
                'errors' => $export['errors'],
            ],
        ], $createdOnExpo ? 201 : 200);
    }

    public function import(
        Request $request,
        Team $team,
        Integration $integration,
        ExpoProject $expoProject,
        ExpoClient $expo,
        ImportExpoProject $importer,
    ): JsonResponse {
        $this->ensureConnectedProject($team, $integration, $expoProject);
        abort_if($expoProject->mobile_app_id, 409, 'This Expo project is already linked.');

        try {
            $remote = $expo->projectWithCredentialSecrets($integration, $expoProject->external_id);
            $result = $importer->handle($team, $expoProject, $remote);
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        } catch (RuntimeException $exception) {
            return $this->operationError($exception->getMessage());
        }

        return response()->json([
            'data' => [
                'app' => (new MobileAppResource($result['app']))->resolve($request),
                'project' => (new ExpoProjectResource(
                    $expoProject->refresh()->load('mobileApp'),
                ))->resolve($request),
            ],
            'meta' => [
                'identifiers_imported' => $result['identifiers'],
                'credentials_imported' => $result['credentials'],
            ],
        ], 201);
    }

    public function sync(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
        SyncExpoProjects $sync,
        ImportExpoProject $importer,
    ): JsonResponse {
        $project = $this->linkedProject($team, $mobileApp);

        try {
            $remote = $expo->projectWithCredentialSecrets(
                $project->integration,
                $project->external_id,
            );
            $result = $importer->handle($team, $project, $remote, $mobileApp);
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        } catch (RuntimeException $exception) {
            return $this->operationError($exception->getMessage());
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

        return response()->json([
            'data' => [
                'app' => (new MobileAppResource($mobileApp->refresh()))->resolve($request),
                'project' => (new ExpoProjectResource(
                    $project->refresh()->load('mobileApp'),
                ))->resolve($request),
            ],
            'meta' => [
                'identifiers_synced' => $result['identifiers'],
                'credentials_synced' => $result['credentials'],
            ],
        ]);
    }

    public function destroy(
        Team $team,
        MobileApp $mobileApp,
    ): Response {
        $project = $mobileApp->expoProject()->firstOrFail();
        abort_unless($project->team_id === $team->id, 404);

        DB::transaction(function () use ($project, $mobileApp): void {
            $mobileApp->ciCdPipelines()
                ->where('integration_id', $project->integration_id)
                ->where('repository_id', $project->external_id)
                ->update(['enabled' => false]);
            $project->update(['mobile_app_id' => null]);
        });

        return response()->noContent();
    }

    private function linkedProject(Team $team, MobileApp $mobileApp): ExpoProject
    {
        $this->ensureEnabled();
        $project = $mobileApp->expoProject()
            ->where('team_id', $team->id)
            ->where('exists_on_expo', true)
            ->whereHas('integration', fn ($query) => $query->activeExpo())
            ->with('integration')
            ->firstOrFail();

        return $project;
    }

    private function ensureConnectedProject(
        Team $team,
        Integration $integration,
        ExpoProject $project,
    ): void {
        $this->ensureEnabled();
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo
            && $integration->status === IntegrationStatus::Active
            && $integration->oauthCredential()->exists()
            && $project->integration_id === $integration->id
            && $project->team_id === $team->id
            && $project->exists_on_expo,
            404,
        );
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
    }

    private function upstreamError(ExpoGraphqlException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'error' => [
                'code' => 'expo_upstream_error',
                'upstream_status' => $exception->status,
                'details' => $exception->errors,
            ],
        ], $exception->status === 503 ? 503 : 502);
    }

    private function operationError(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => ['code' => 'expo_operation_failed'],
        ], $status);
    }
}
