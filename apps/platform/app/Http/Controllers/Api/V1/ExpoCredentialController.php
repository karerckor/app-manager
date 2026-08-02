<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpoCredentialLinkResource;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExpoCredentialController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
        string $credential,
        ExpoCredentialExporter $exporter,
    ): JsonResponse {
        $project = $this->project($team, $mobileApp, $appIdentifier);
        $credential = $team->credentials()->findOrFail($credential);
        abort_unless(
            $this->isAttached($credential, $appIdentifier),
            404,
        );
        $alreadyLinked = $project->credentialLinks()
            ->where('credential_id', $credential->id)
            ->where('app_identifier_id', $appIdentifier->id)
            ->exists();

        try {
            $result = $exporter->export($project, $appIdentifier, $credential);
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'credential' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'data' => (new ExpoCredentialLinkResource($result['link']))->resolve($request),
            'meta' => ['replaced_remote_link' => $alreadyLinked],
        ], $alreadyLinked ? 200 : 201);
    }

    public function provision(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
        ExpoCredentialExporter $exporter,
    ): JsonResponse {
        $project = $this->project($team, $mobileApp, $appIdentifier);
        $data = $request->validate([
            'credential_ids' => ['sometimes', 'array', 'max:50'],
            'credential_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $appIdentifier->load([
            'credentials.media',
            'profiles.credentials.media',
        ]);
        $attached = $appIdentifier->credentials
            ->concat($appIdentifier->profiles->flatMap(fn ($profile) => $profile->credentials))
            ->unique('id');

        if (array_key_exists('credential_ids', $data)) {
            $requested = collect($data['credential_ids']);
            if ($requested->diff($attached->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'credential_ids' => 'One or more credentials are not attached to this identifier.',
                ]);
            }
            $credentials = $attached->whereIn('id', $requested);
        } else {
            $credentials = $attached->filter(fn (Credential $credential) => (
                ExpoCredentialExporter::supports($credential->type)
                && $credential->getFirstMedia('file') !== null
            ));
        }

        try {
            $remoteIdentifier = $exporter->provisionIdentifier($project, $appIdentifier);
        } catch (ExpoGraphqlException $exception) {
            return $this->upstreamError($exception);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'identifier' => $exception->getMessage(),
            ]);
        }

        $links = [];
        $errors = [];

        foreach ($credentials as $credential) {
            try {
                $links[] = $exporter->export(
                    $project,
                    $appIdentifier,
                    $credential,
                )['link'];
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $errors[] = [
                    'credential_id' => $credential->id,
                    'name' => $credential->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => [
                'identifier' => [
                    'app_identifier_id' => $appIdentifier->id,
                    'remote_resource_type' => $remoteIdentifier['type'],
                    'remote_resource_id' => $remoteIdentifier['id'],
                ],
                'credentials' => collect($links)->map(
                    fn ($link) => (new ExpoCredentialLinkResource($link))->resolve($request),
                )->values(),
                'errors' => $errors,
            ],
        ]);
    }

    private function project(
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): ExpoProject {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
        abort_unless($appIdentifier->mobile_app_id === $mobileApp->id, 404);

        return $mobileApp->expoProject()
            ->where('team_id', $team->id)
            ->where('exists_on_expo', true)
            ->whereHas('integration', fn ($query) => $query->activeExpo())
            ->with('integration')
            ->firstOrFail();
    }

    private function isAttached(Credential $credential, AppIdentifier $identifier): bool
    {
        return $credential->appIdentifiers()->whereKey($identifier->id)->exists()
            || $credential->profiles()
                ->where('app_identifier_id', $identifier->id)
                ->exists();
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
}
