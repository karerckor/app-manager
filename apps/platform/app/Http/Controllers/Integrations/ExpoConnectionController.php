<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\SyncExpoProjects;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpoConnectionController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        ExpoClient $expo,
        OAuthCredentialStore $credentials,
        SyncExpoProjects $sync,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeTeam($request, $team);
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

            $accountName = (string) ($account['name'] ?? $account['id']);
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

        try {
            $sync->handle($integration);
        } catch (ExpoGraphqlException $exception) {
            $integration->update([
                'status' => IntegrationStatus::Degraded,
                'settings' => [
                    ...($integration->settings ?? []),
                    'last_sync_error' => $exception->getMessage(),
                    'last_sync_failed_at' => now()->toIso8601String(),
                ],
            ]);

            return back()->with(
                'status',
                'Expo connected, but projects could not be synced: '.$exception->getMessage(),
            );
        }

        $this->markSyncSuccessful($integration);

        return back()->with('status', 'Expo account connected.');
    }

    public function sync(
        Request $request,
        Team $team,
        Integration $integration,
        ExpoClient $expo,
        SyncExpoProjects $sync,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->assertIntegration($request, $team, $integration);
        abort_unless(
            in_array($integration->status, [
                IntegrationStatus::Active,
                IntegrationStatus::Degraded,
            ], true)
            && $integration->oauthCredential()->exists(),
            404,
        );

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
            $sync->handle($integration);
            $this->markSyncSuccessful($integration);
        } catch (ExpoGraphqlException $exception) {
            $integration->update([
                'status' => IntegrationStatus::Degraded,
                'settings' => [
                    ...($integration->settings ?? []),
                    'last_sync_error' => $exception->getMessage(),
                    'last_sync_failed_at' => now()->toIso8601String(),
                ],
            ]);

            throw ValidationException::withMessages([
                'expo' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Expo projects synced.');
    }

    public function disconnect(
        Request $request,
        Team $team,
        Integration $integration,
        OAuthCredentialStore $credentials,
    ): RedirectResponse {
        $this->assertIntegration($request, $team, $integration);
        $integration->update([
            'status' => IntegrationStatus::Disconnected,
            'updated_by_user_id' => $request->user()->id,
            'disconnected_at' => now(),
        ]);
        $credentials->forget($integration);

        return back()->with(
            'status',
            'Expo disconnected. Project links and remote history were preserved.',
        );
    }

    private function assertIntegration(Request $request, Team $team, Integration $integration): void
    {
        $this->authorizeTeam($request, $team);
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo,
            404,
        );
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
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
}
