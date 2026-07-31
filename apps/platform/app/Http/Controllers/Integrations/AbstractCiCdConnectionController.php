<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\ConnectedAccount;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class AbstractCiCdConnectionController extends Controller
{
    abstract protected function provider(): IntegrationProvider;

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
        $integration->pipelines()->update(['enabled' => false]);
        $credentials->forget($integration);

        return back()->with(
            'status',
            $this->provider()->label().' disconnected. Historical builds were preserved.',
        );
    }

    protected function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config("integrations.{$this->provider()->value}.enabled"),
            404,
        );
    }

    protected function authorizeTeam(Request $request, Team $team): void
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
    }

    protected function assertIntegration(Request $request, Team $team, Integration $integration): void
    {
        $this->authorizeTeam($request, $team);
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === $this->provider(),
            404,
        );
    }

    protected function persistConnection(
        Request $request,
        Team $team,
        ConnectedAccount $account,
    ): Integration {
        $integration = Integration::withTrashed()->firstOrNew([
            'team_id' => $team->id,
            'provider' => $this->provider()->value,
            'external_id' => $account->externalId,
        ]);

        if ($integration->exists && $integration->trashed()) {
            $integration->restore();
        }

        $integration->fill([
            'kind' => IntegrationKind::CiCd,
            'name' => $account->name,
            'status' => IntegrationStatus::Active,
            'external_account_id' => $account->externalAccountId,
            'external_account_name' => $account->externalAccountName,
            'settings' => $account->settings,
            'created_by_user_id' => $integration->created_by_user_id ?: $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
            'last_synced_at' => now(),
            'disabled_at' => null,
            'disconnected_at' => null,
        ]);
        $integration->save();

        return $integration;
    }
}
