<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpoIdentifierController extends Controller
{
    public function index(
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): JsonResponse {
        $project = $this->linkedProject($team, $mobileApp);

        try {
            $remote = $expo->projectIdentifiers(
                $project->integration,
                $project->external_id,
            );
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        }

        if (blank($remote['id'] ?? null)) {
            return $this->operationError('Expo did not return the linked project.', 502);
        }

        $remoteIdentifiers = $this->remoteIdentifiers($remote);
        $remoteByKey = $remoteIdentifiers->keyBy(fn (array $identifier) => $this->key(
            $identifier['platform'],
            $identifier['identifier'],
        ));
        $local = $mobileApp->appIdentifiers()
            ->whereIn('platform', [Platform::Ios->value, Platform::Android->value])
            ->orderBy('platform')
            ->orderBy('identifier')
            ->get();
        $localByKey = $local->keyBy(fn ($identifier) => $this->key(
            $identifier->platform->value,
            $identifier->identifier,
        ));
        $items = $local->map(fn ($identifier) => [
            'local_id' => $identifier->id,
            'platform' => $identifier->platform->value,
            'identifier' => $identifier->identifier,
            'name' => $identifier->name,
            'sources' => [
                'app_manager' => true,
                'expo' => $remoteByKey->has($this->key(
                    $identifier->platform->value,
                    $identifier->identifier,
                )),
            ],
        ]);

        foreach ($remoteIdentifiers as $identifier) {
            if (! $localByKey->has($this->key(
                $identifier['platform'],
                $identifier['identifier'],
            ))) {
                $items->push([
                    'local_id' => null,
                    'platform' => $identifier['platform'],
                    'identifier' => $identifier['identifier'],
                    'name' => null,
                    'sources' => [
                        'app_manager' => false,
                        'expo' => true,
                    ],
                ]);
            }
        }

        return response()->json([
            'data' => $items
                ->map(fn (array $identifier) => [
                    ...$identifier,
                    'sync_status' => match ($identifier['sources']) {
                        ['app_manager' => true, 'expo' => true] => 'synced',
                        ['app_manager' => true, 'expo' => false] => 'app_manager_only',
                        default => 'expo_only',
                    },
                ])
                ->sortBy([
                    ['platform', 'asc'],
                    ['identifier', 'asc'],
                ])
                ->values(),
        ]);
    }

    public function sync(
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
        ExpoCredentialExporter $exporter,
        BlueprintComplianceEvaluator $blueprints,
    ): JsonResponse {
        $project = $this->linkedProject($team, $mobileApp);

        try {
            $remote = $expo->projectIdentifiers(
                $project->integration,
                $project->external_id,
            );
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        }

        if (blank($remote['id'] ?? null)) {
            return $this->operationError('Expo did not return the linked project.', 502);
        }

        $remoteIdentifiers = $this->remoteIdentifiers($remote);
        $imported = DB::transaction(function () use ($mobileApp, $remoteIdentifiers): int {
            $imported = 0;

            foreach ($remoteIdentifiers as $candidate) {
                $identifier = $mobileApp->appIdentifiers()->firstOrNew([
                    'platform' => $candidate['platform'],
                    'identifier' => $candidate['identifier'],
                ]);
                if (! $identifier->exists) {
                    $identifier->name = $candidate['platform'] === Platform::Ios->value
                        ? 'iOS'
                        : 'Android';
                    $identifier->save();
                    $imported++;
                }
            }

            return $imported;
        });

        $remoteKeys = $remoteIdentifiers->mapWithKeys(fn (array $identifier) => [
            $this->key($identifier['platform'], $identifier['identifier']) => true,
        ]);
        $pushed = 0;
        $errors = [];
        $blueprints->evaluate($mobileApp);

        foreach ($mobileApp->appIdentifiers()->orderBy('platform')->get() as $identifier) {
            if ($identifier->platform === Platform::Web) {
                continue;
            }

            if ($remoteKeys->has($this->key(
                $identifier->platform->value,
                $identifier->identifier,
            ))) {
                continue;
            }

            try {
                $exporter->provisionIdentifier($project, $identifier);
                $pushed++;
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $errors[] = [
                    'app_identifier_id' => $identifier->id,
                    'identifier' => $identifier->identifier,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => [
                'imported_from_expo' => $imported,
                'pushed_to_expo' => $pushed,
                'errors' => $errors,
            ],
        ]);
    }

    private function linkedProject(Team $team, MobileApp $mobileApp): ExpoProject
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );

        return $mobileApp->expoProject()
            ->where('team_id', $team->id)
            ->where('exists_on_expo', true)
            ->whereHas('integration', fn ($query) => $query
                ->where('status', IntegrationStatus::Active->value)
                ->whereHas('oauthCredential'))
            ->with('integration')
            ->firstOrFail();
    }

    /** @return Collection<int, array{platform: string, identifier: string}> */
    private function remoteIdentifiers(array $remote): Collection
    {
        $identifiers = collect();

        foreach ((array) ($remote['androidAppCredentials'] ?? []) as $credentials) {
            $identifier = trim((string) ($credentials['applicationIdentifier'] ?? ''));
            if ($identifier !== '') {
                $identifiers->push([
                    'platform' => Platform::Android->value,
                    'identifier' => $identifier,
                ]);
            }
        }

        foreach ((array) ($remote['iosAppCredentials'] ?? []) as $credentials) {
            $identifier = trim((string) data_get(
                $credentials,
                'appleAppIdentifier.bundleIdentifier',
                '',
            ));
            if ($identifier !== '') {
                $identifiers->push([
                    'platform' => Platform::Ios->value,
                    'identifier' => $identifier,
                ]);
            }
        }

        return $identifiers
            ->unique(fn (array $identifier) => $this->key(
                $identifier['platform'],
                $identifier['identifier'],
            ))
            ->values();
    }

    private function key(string $platform, string $identifier): string
    {
        return $platform.'|'.$identifier;
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

    private function operationError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => ['code' => 'expo_operation_failed'],
        ], $status);
    }
}
