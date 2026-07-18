<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\CiCd\GitHub\Connection\GitHubConnectionStateStore;
use App\Integrations\CiCd\GitHub\GitHubClient;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GitHubConnectionController extends Controller
{
    public function connect(
        Request $request,
        Team $team,
        GitHubConnectionStateStore $states,
        GitHubClient $github,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);

        $state = $states->issue($request->user(), $team);

        return redirect()->away($github->installationUrl($state));
    }

    public function callback(
        Request $request,
        GitHubConnectionStateStore $states,
        GitHubClient $github,
    ): RedirectResponse {
        $this->ensureEnabled();
        $data = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:1000'],
            'installation_id' => ['required', 'integer', 'min:1'],
        ]);
        $context = $states->consume($data['state']);

        abort_unless($context && $context['user_id'] === $request->user()->id, 419);

        $team = Team::query()->findOrFail($context['team_id']);
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);

        $installationId = (string) $data['installation_id'];
        $userToken = $github->exchangeUserCode($data['code']);
        $accessible = collect($github->userInstallations($userToken))
            ->contains(fn (array $installation) => (string) ($installation['id'] ?? '') === $installationId);

        abort_unless($accessible, 403, 'The GitHub installation is not accessible to the current GitHub user.');

        $installation = $github->installation($installationId);
        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];
        $integration = Integration::withTrashed()->firstOrNew([
            'team_id' => $team->id,
            'provider' => IntegrationProvider::GitHub->value,
            'external_id' => $installationId,
        ]);
        $integration->fill([
            'kind' => IntegrationKind::CiCd,
            'name' => 'GitHub '.($account['login'] ?? $installationId),
            'status' => IntegrationStatus::Active,
            'external_account_id' => isset($account['id']) ? (string) $account['id'] : null,
            'external_account_name' => $account['login'] ?? null,
            'settings' => [
                'target_type' => $installation['target_type'] ?? null,
                'repository_selection' => $installation['repository_selection'] ?? null,
                'permissions' => $installation['permissions'] ?? [],
            ],
            'created_by_user_id' => $integration->created_by_user_id ?: $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
            'last_synced_at' => now(),
            'disabled_at' => null,
            'disconnected_at' => null,
        ]);
        if ($integration->exists && $integration->trashed()) {
            $integration->restore();
        }

        $integration->save();

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
        $this->assertGitHubIntegration($request, $team, $integration);
        $installation = $github->installation((string) $integration->external_id);
        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];

        $integration->update([
            'status' => IntegrationStatus::Active,
            'external_account_id' => isset($account['id']) ? (string) $account['id'] : null,
            'external_account_name' => $account['login'] ?? null,
            'settings' => [
                'target_type' => $installation['target_type'] ?? null,
                'repository_selection' => $installation['repository_selection'] ?? null,
                'permissions' => $installation['permissions'] ?? [],
            ],
            'updated_by_user_id' => $request->user()->id,
            'last_synced_at' => now(),
            'disabled_at' => null,
        ]);

        return back()->with('status', 'GitHub connection synced.');
    }

    public function disconnect(Request $request, Team $team, Integration $integration): RedirectResponse
    {
        $this->assertGitHubIntegration($request, $team, $integration);

        $integration->update([
            'status' => IntegrationStatus::Disconnected,
            'updated_by_user_id' => $request->user()->id,
            'disconnected_at' => now(),
        ]);
        $integration->pipelines()->update(['enabled' => false]);

        return back()->with('status', 'GitHub disconnected. Historical builds were preserved.');
    }

    private function assertGitHubIntegration(Request $request, Team $team, Integration $integration): void
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        abort_unless(
            $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::GitHub,
            404,
        );
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.github.enabled'),
            404,
        );
    }
}
