<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Integrations\CiCd\Connection\ConnectionStateStore;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\ConnectedAccount;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Integrations\CiCd\GitLab\GitLabClient;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GitLabConnectionController extends AbstractCiCdConnectionController
{
    public function connect(
        Request $request,
        Team $team,
        ConnectionStateStore $states,
        GitLabClient $gitlab,
    ): RedirectResponse {
        $this->ensureEnabled();
        $this->authorizeTeam($request, $team);

        $state = $states->issue($request->user(), $team, $this->provider());
        $hasConnectedAccount = $team->integrations()
            ->where('provider', $this->provider()->value)
            ->where('status', IntegrationStatus::Active->value)
            ->get(['settings'])
            ->contains(fn ($integration) => data_get(
                $integration->settings,
                'connection_type',
            ) !== 'self_hosted');

        return redirect()->away($gitlab->authorizationUrl($state, $hasConnectedAccount));
    }

    public function storeSelfHosted(
        Request $request,
        Team $team,
        OAuthCredentialStore $credentials,
        GitLabClient $gitlab,
    ): RedirectResponse {
        $this->ensureSelfHostedEnabled();
        $this->authorizeTeam($request, $team);

        $data = $request->validate([
            'instance_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'access_token' => ['required', 'string', 'max:10000'],
        ]);
        [$webUrl, $apiUrl] = $this->selfHostedUrls($data['instance_url']);

        try {
            $user = $gitlab->userWithPrivateToken($apiUrl, $data['access_token']);
        } catch (RequestException $exception) {
            $field = in_array($exception->response->status(), [401, 403], true)
                ? 'access_token'
                : 'instance_url';
            $message = $field === 'access_token'
                ? 'GitLab rejected this access token. Use a token with the api scope.'
                : 'The GitLab API could not be reached at this instance URL.';

            throw ValidationException::withMessages([$field => $message]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'instance_url' => 'The GitLab instance could not be reached.',
            ]);
        }

        abort_unless(isset($user['id']), 502, 'GitLab did not return the connected user.');

        DB::transaction(function () use (
            $request,
            $team,
            $user,
            $webUrl,
            $apiUrl,
            $data,
            $credentials,
        ): void {
            $integration = $this->persistConnection(
                $request,
                $team,
                $this->account($user, $webUrl, $apiUrl, true),
            );
            $credentials->put($integration, new OAuthTokens(
                accessToken: $data['access_token'],
                tokenType: 'Private-Token',
                scopes: ['api'],
            ));
        });

        return back()->with('status', 'Self-hosted GitLab connected.');
    }

    public function callback(
        Request $request,
        ConnectionStateStore $states,
        OAuthCredentialStore $credentials,
        GitLabClient $gitlab,
    ): RedirectResponse {
        $this->ensureEnabled();
        $data = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:1000'],
        ]);
        $context = $states->consume($data['state'], $this->provider());

        abort_unless($context && $context['user_id'] === $request->user()->id, 419);

        $team = Team::query()->findOrFail($context['team_id']);
        $this->authorizeTeam($request, $team);
        $tokens = $gitlab->exchangeCode($data['code']);
        $user = $gitlab->userWithToken($tokens->accessToken);
        abort_unless(isset($user['id']), 502, 'GitLab did not return the connected user.');

        DB::transaction(function () use ($request, $team, $user, $tokens, $credentials): void {
            $integration = $this->persistConnection($request, $team, $this->account($user));
            $credentials->put($integration, $tokens);
        });

        return redirect()
            ->route('accounts.integrations.index', $team)
            ->with('status', 'GitLab connected.');
    }

    public function sync(
        Request $request,
        Team $team,
        Integration $integration,
        GitLabClient $gitlab,
    ): RedirectResponse {
        $this->assertIntegration($request, $team, $integration);
        $settings = $integration->settings ?? [];
        $selfHosted = data_get($settings, 'connection_type') === 'self_hosted';
        $this->persistConnection($request, $team, $this->account(
            $gitlab->user($integration),
            $selfHosted ? data_get($settings, 'gitlab_web_url') : null,
            $selfHosted ? data_get($settings, 'gitlab_api_url') : null,
            $selfHosted,
            $integration->external_id,
        ));

        return back()->with('status', 'GitLab connection synced.');
    }

    protected function provider(): IntegrationProvider
    {
        return IntegrationProvider::GitLab;
    }

    private function ensureSelfHostedEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.gitlab.self_hosted_enabled'),
            404,
        );
    }

    /** @return array{string, string} */
    private function selfHostedUrls(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'instance_url' => 'Enter the GitLab instance URL without credentials, query, or fragment.',
            ]);
        }

        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower($parts['host']), '[]');
        $host = str_contains($host, ':') ? "[{$host}]" : $host;
        $port = isset($parts['port'])
            && ! (($scheme === 'https' && $parts['port'] === 443)
                || ($scheme === 'http' && $parts['port'] === 80))
            ? ':'.$parts['port']
            : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $webUrl = "{$scheme}://{$host}{$port}{$path}";

        return [$webUrl, $webUrl.'/api/v4'];
    }

    /** @param array<string, mixed> $user */
    private function account(
        array $user,
        ?string $webUrl = null,
        ?string $apiUrl = null,
        bool $selfHosted = false,
        ?string $externalId = null,
    ): ConnectedAccount {
        $id = (string) ($user['id'] ?? '');
        $username = (string) ($user['username'] ?? $user['name'] ?? $id);
        $host = is_string($webUrl) ? parse_url($webUrl, PHP_URL_HOST) : null;
        $externalId ??= $selfHosted
            ? 'self-hosted:'.hash('sha256', (string) $webUrl).':'.$id
            : $id;

        return new ConnectedAccount(
            externalId: $externalId,
            name: $selfHosted && is_string($host) && $host !== ''
                ? "GitLab {$username} · {$host}"
                : 'GitLab '.$username,
            externalAccountId: $id,
            externalAccountName: $username,
            settings: [
                'name' => $user['name'] ?? null,
                'web_url' => $user['web_url'] ?? null,
                ...($selfHosted ? [
                    'connection_type' => 'self_hosted',
                    'gitlab_web_url' => $webUrl,
                    'gitlab_api_url' => $apiUrl,
                ] : []),
            ],
        );
    }
}
