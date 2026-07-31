<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\SyncExpoProjects;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpoProjectResource;
use App\Http\Resources\Api\V1\IntegrationResource;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpoIntegrationController extends Controller
{
    use ResolvesPageSize;

    public function store(
        Request $request,
        Team $team,
        ExpoClient $expo,
        OAuthCredentialStore $credentials,
        SyncExpoProjects $sync,
    ): JsonResponse {
        $this->ensureEnabled();
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:10000'],
            'account_name' => ['nullable', 'string', 'max:255'],
        ]);
        $requestedAccount = trim((string) ($data['account_name'] ?? ''));

        try {
            if ($requestedAccount !== '') {
                $actor = [];
                $account = $expo->accountByNameWithToken(
                    $data['access_token'],
                    $requestedAccount,
                );
                $accounts = collect([$account])->filter();
            } else {
                $actor = $expo->actorWithToken($data['access_token']);
                $accounts = collect((array) ($actor['accounts'] ?? []));
                $account = $accounts->count() === 1 ? $accounts->first() : null;
            }
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages([
                'access_token' => $exception->getMessage(),
            ]);
        }

        if (! is_array($account) || ! isset($account['id'], $account['name'])) {
            $available = $accounts->pluck('name')->filter()->implode(', ');
            throw ValidationException::withMessages([
                'account_name' => $requestedAccount === ''
                    ? 'Choose an Expo account accessible to this token'.($available ? ": {$available}." : '.')
                    : 'This token cannot access that Expo account'.($available ? ". Available: {$available}." : '.'),
            ]);
        }

        $integration = DB::transaction(function () use (
            $request,
            $team,
            $actor,
            $account,
            $data,
            $credentials,
        ): Integration {
            $integration = Integration::withTrashed()->firstOrNew([
                'team_id' => $team->id,
                'provider' => IntegrationProvider::Expo->value,
                'external_id' => (string) $account['id'],
            ]);

            if ($integration->exists && $integration->trashed()) {
                $integration->restore();
            }

            $accountName = (string) $account['name'];
            $integration->fill([
                'kind' => IntegrationKind::AppService,
                'name' => 'Expo '.$accountName,
                'status' => IntegrationStatus::Active,
                'external_account_id' => (string) $account['id'],
                'external_account_name' => $accountName,
                'settings' => [
                    'actor_id' => (string) ($actor['id'] ?? ''),
                    'actor_name' => (string) ($actor['displayName'] ?? ''),
                    'account_display_name' => $account['displayName'] ?? null,
                ],
                'created_by_user_id' => $integration->created_by_user_id ?: $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                'disabled_at' => null,
                'disconnected_at' => null,
            ]);
            $integration->save();
            $credentials->put($integration, new OAuthTokens(
                accessToken: $data['access_token'],
                tokenType: 'Bearer',
            ));

            return $integration;
        });

        $syncError = null;

        try {
            $sync->handle($integration);
            $this->markSyncSuccessful($integration);
        } catch (ExpoGraphqlException $exception) {
            $syncError = $exception->getMessage();
            $this->markSyncFailed($integration, $syncError);
        }

        return response()->json([
            'data' => (new IntegrationResource($integration->refresh()))->resolve($request),
            'meta' => [
                'projects_count' => $integration->expoProjects()->count(),
                'sync_error' => $syncError,
            ],
        ], $integration->wasRecentlyCreated ? 201 : 200);
    }

    public function sync(
        Request $request,
        Team $team,
        Integration $integration,
        ExpoClient $expo,
        SyncExpoProjects $sync,
    ): JsonResponse {
        $this->ensureConnected($team, $integration, allowDegraded: true);

        try {
            $account = $expo->account($integration);
            $integration->update([
                'name' => 'Expo '.(string) ($account['name'] ?? $integration->external_account_name),
                'external_account_name' => (string) ($account['name'] ?? $integration->external_account_name),
                'settings' => [
                    ...($integration->settings ?? []),
                    'account_display_name' => $account['displayName'] ?? null,
                ],
                'updated_by_user_id' => $request->user()->id,
            ]);
            $projects = $sync->handle($integration);
            $this->markSyncSuccessful($integration);
        } catch (ExpoGraphqlException $exception) {
            $this->markSyncFailed($integration, $exception->getMessage());

            return $this->upstreamError($exception);
        }

        return response()->json([
            'data' => (new IntegrationResource($integration->refresh()))->resolve($request),
            'meta' => ['projects_count' => count($projects)],
        ]);
    }

    public function projects(
        Request $request,
        Team $team,
        Integration $integration,
    ): AnonymousResourceCollection {
        $this->ensureConnected($team, $integration, allowDegraded: true);

        return ExpoProjectResource::collection(
            $integration->expoProjects()
                ->with('mobileApp')
                ->orderByDesc('exists_on_expo')
                ->orderBy('name')
                ->paginate($this->perPage($request)),
        );
    }

    public function destroy(
        Team $team,
        Integration $integration,
        OAuthCredentialStore $credentials,
    ): Response {
        $this->ensureExpoIntegration($team, $integration);
        $integration->update([
            'status' => IntegrationStatus::Disconnected,
            'disconnected_at' => now(),
        ]);
        $credentials->forget($integration);

        return response()->noContent();
    }

    private function ensureConnected(
        Team $team,
        Integration $integration,
        bool $allowDegraded = false,
    ): void {
        $this->ensureEnabled();
        $this->ensureExpoIntegration($team, $integration);
        $statuses = $allowDegraded
            ? [IntegrationStatus::Active, IntegrationStatus::Degraded]
            : [IntegrationStatus::Active];
        abort_unless(
            in_array($integration->status, $statuses, true)
            && $integration->oauthCredential()->exists(),
            404,
        );
    }

    private function ensureExpoIntegration(Team $team, Integration $integration): void
    {
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo,
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

    private function markSyncSuccessful(Integration $integration): void
    {
        $settings = $integration->settings ?? [];
        unset($settings['last_sync_error'], $settings['last_sync_failed_at']);

        $integration->update([
            'status' => IntegrationStatus::Active,
            'settings' => $settings,
        ]);
    }

    private function markSyncFailed(Integration $integration, string $message): void
    {
        $integration->update([
            'status' => IntegrationStatus::Degraded,
            'settings' => [
                ...($integration->settings ?? []),
                'last_sync_error' => $message,
                'last_sync_failed_at' => now()->toIso8601String(),
            ],
        ]);
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
