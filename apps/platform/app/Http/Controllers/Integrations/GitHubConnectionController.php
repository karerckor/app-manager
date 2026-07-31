<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Integrations\CiCd\Connection\ConnectionStateStore;
use App\Integrations\CiCd\Data\ConnectedAccount;
use App\Integrations\CiCd\GitHub\GitHubClient;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GitHubConnectionController extends AbstractCiCdConnectionController
{
    public function connect(
        Request $request,
        Team $team,
        ConnectionStateStore $states,
        GitHubClient $github,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeTeam($request, $team);

        $state = $states->issue($request->user(), $team, $this->provider());
        $hasConnectedAccount = $team->integrations()
            ->where('provider', $this->provider()->value)
            ->where('status', IntegrationStatus::Active->value)
            ->exists();

        return redirect()->away($github->authorizationUrl($state, $hasConnectedAccount));
    }

    public function callback(
        Request $request,
        ConnectionStateStore $states,
        GitHubClient $github,
    ): RedirectResponse {
        $this->ensureEnabled();
        $data = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:1000'],
            'installation_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $context = $states->consume($data['state'], $this->provider());

        abort_unless($context && $context['user_id'] === $request->user()->id, 419);

        $team = Team::query()->findOrFail($context['team_id']);
        $this->authorizeTeam($request, $team);

        $userToken = $github->exchangeUserCode($data['code']);
        $accessibleInstallations = collect($github->userInstallations($userToken))
            ->filter(fn (array $installation) => isset($installation['id']))
            ->values();

        if (! isset($data['installation_id'])) {
            $connectedInstallationIds = $team->integrations()
                ->where('provider', $this->provider()->value)
                ->where('status', IntegrationStatus::Active->value)
                ->pluck('external_id')
                ->filter()
                ->map(fn ($id) => (string) $id);
            $availableInstallations = $accessibleInstallations
                ->reject(fn (array $installation) => $connectedInstallationIds->contains(
                    (string) $installation['id'],
                ))
                ->values();

            if ($availableInstallations->count() !== 1) {
                $installationState = $states->issue($request->user(), $team, $this->provider());

                return redirect()->away($github->installationUrl($installationState));
            }

            $installationId = (string) $availableInstallations->first()['id'];
        } else {
            $installationId = (string) $data['installation_id'];
            $accessible = $accessibleInstallations
                ->contains(fn (array $installation) => (string) $installation['id'] === $installationId);

            abort_unless($accessible, 403, 'The GitHub installation is not accessible to the current GitHub user.');
        }

        $installation = $github->installation($installationId);
        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];
        $this->persistConnection($request, $team, new ConnectedAccount(
            externalId: $installationId,
            name: 'GitHub '.($account['login'] ?? $installationId),
            externalAccountId: isset($account['id']) ? (string) $account['id'] : null,
            externalAccountName: $account['login'] ?? null,
            settings: [
                'target_type' => $installation['target_type'] ?? null,
                'repository_selection' => $installation['repository_selection'] ?? null,
                'permissions' => $installation['permissions'] ?? [],
            ],
        ));

        return redirect()
            ->route('accounts.integrations.index', $team)
            ->with('status', 'GitHub connected.');
    }

    public function sync(
        Request $request,
        Team $team,
        Integration $integration,
        GitHubClient $github,
    ): RedirectResponse {
        $this->assertIntegration($request, $team, $integration);
        $installation = $github->installation((string) $integration->external_id);
        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];

        $this->persistConnection($request, $team, new ConnectedAccount(
            externalId: (string) $integration->external_id,
            name: 'GitHub '.($account['login'] ?? $integration->external_id),
            externalAccountId: isset($account['id']) ? (string) $account['id'] : null,
            externalAccountName: $account['login'] ?? null,
            settings: [
                'target_type' => $installation['target_type'] ?? null,
                'repository_selection' => $installation['repository_selection'] ?? null,
                'permissions' => $installation['permissions'] ?? [],
            ],
        ));

        return back()->with('status', 'GitHub connection synced.');
    }

    protected function provider(): IntegrationProvider
    {
        return IntegrationProvider::GitHub;
    }
}
