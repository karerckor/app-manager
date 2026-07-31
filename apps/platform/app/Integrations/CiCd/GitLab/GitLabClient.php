<?php

namespace App\Integrations\CiCd\GitLab;

use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Models\Integration;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitLabClient
{
    public function __construct(private readonly OAuthCredentialStore $credentials) {}

    public function authorizationUrl(string $state, bool $forceLogin = false): string
    {
        $this->assertOAuthConfigured();
        $scopes = $this->configuredScopes();

        if ($forceLogin && ! in_array('openid', $scopes, true)) {
            throw new RuntimeException(
                'GitLab OAuth scope "openid" must be configured to connect another account.',
            );
        }

        $query = [
            'client_id' => config('integrations.gitlab.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(' ', $scopes),
        ];

        if ($forceLogin) {
            $query['prompt'] = 'login';
        }

        return rtrim((string) config('integrations.gitlab.web_url'), '/')
            .'/oauth/authorize?'.http_build_query($query);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        $this->assertOAuthConfigured();

        $response = Http::acceptJson()
            ->asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->post(rtrim((string) config('integrations.gitlab.web_url'), '/').'/oauth/token', [
                'client_id' => config('integrations.gitlab.client_id'),
                'client_secret' => config('integrations.gitlab.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw();

        return $this->tokens($response);
    }

    public function refresh(OAuthTokens $tokens): OAuthTokens
    {
        if (! is_string($tokens->refreshToken) || $tokens->refreshToken === '') {
            throw new RuntimeException('GitLab OAuth refresh token is missing. Reconnect the integration.');
        }

        $this->assertOAuthConfigured();
        $response = Http::acceptJson()
            ->asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->post(rtrim((string) config('integrations.gitlab.web_url'), '/').'/oauth/token', [
                'client_id' => config('integrations.gitlab.client_id'),
                'client_secret' => config('integrations.gitlab.client_secret'),
                'refresh_token' => $tokens->refreshToken,
                'grant_type' => 'refresh_token',
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw();

        return $this->tokens($response, $tokens->refreshToken);
    }

    /** @return array<string, mixed> */
    public function userWithToken(string $accessToken): array
    {
        return $this->requestWithToken(
            rtrim((string) config('integrations.gitlab.api_url'), '/'),
            $accessToken,
        )->get('/user')->throw()->json();
    }

    /** @return array<string, mixed> */
    public function userWithPrivateToken(string $apiUrl, string $accessToken): array
    {
        return $this->requestWithToken($apiUrl, $accessToken, 'Private-Token')
            ->get('/user')
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function user(Integration $integration): array
    {
        return $this->request($integration)->get('/user')->throw()->json();
    }

    /** @return list<array<string, mixed>> */
    public function projects(Integration $integration): array
    {
        return $this->paginate($integration, '/projects', [
            'membership' => 'true',
            'min_access_level' => 30,
            'archived' => 'false',
            'order_by' => 'path',
            'sort' => 'asc',
        ]);
    }

    /** @return array<string, mixed> */
    public function project(Integration $integration, string $projectId): array
    {
        return $this->request($integration)
            ->get('/projects/'.rawurlencode($projectId))
            ->throw()
            ->json();
    }

    public function repositoryFile(
        Integration $integration,
        string $projectId,
        string $path,
        string $ref,
    ): string {
        $response = $this->request($integration)
            ->get('/projects/'.rawurlencode($projectId).'/repository/files/'.rawurlencode(ltrim($path, '/')), [
                'ref' => $ref,
            ])
            ->throw();
        $contents = $response->json('content');

        if (! is_string($contents)) {
            throw new RuntimeException('GitLab CI configuration contents are missing.');
        }

        $decoded = base64_decode(str_replace("\n", '', $contents), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('GitLab CI configuration contents are not valid base64.');
        }

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    public function branches(Integration $integration, string $projectId): array
    {
        return $this->paginate($integration, '/projects/'.rawurlencode($projectId).'/repository/branches');
    }

    /** @return list<array<string, mixed>> */
    public function tags(Integration $integration, string $projectId): array
    {
        return $this->paginate($integration, '/projects/'.rawurlencode($projectId).'/repository/tags');
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function createPipeline(Integration $integration, string $projectId, string $ref, array $inputs): array
    {
        return $this->request($integration)
            ->post('/projects/'.rawurlencode($projectId).'/pipeline', [
                'ref' => $ref,
                'inputs' => (object) $inputs,
            ])
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function pipeline(Integration $integration, string $projectId, string $pipelineId): array
    {
        return $this->request($integration)
            ->get('/projects/'.rawurlencode($projectId).'/pipelines/'.rawurlencode($pipelineId))
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function cancelPipeline(Integration $integration, string $projectId, string $pipelineId): array
    {
        return $this->request($integration)
            ->post('/projects/'.rawurlencode($projectId).'/pipelines/'.rawurlencode($pipelineId).'/cancel')
            ->throw()
            ->json();
    }

    /** @return list<array<string, mixed>> */
    public function pipelineJobs(Integration $integration, string $projectId, string $pipelineId): array
    {
        return $this->paginate(
            $integration,
            '/projects/'.rawurlencode($projectId).'/pipelines/'.rawurlencode($pipelineId).'/jobs',
            ['include_retried' => 'true'],
        );
    }

    public function jobTrace(Integration $integration, string $projectId, string $jobId): string
    {
        return $this->request($integration)
            ->get('/projects/'.rawurlencode($projectId).'/jobs/'.rawurlencode($jobId).'/trace')
            ->throw()
            ->body();
    }

    /** @param array<string, string|int> $query */
    private function paginate(Integration $integration, string $path, array $query = []): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->request($integration)
                ->get($path, [...$query, 'per_page' => 100, 'page' => $page])
                ->throw();
            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('GitLab returned an invalid paginated response.');
            }

            $items = [...$items, ...array_values($data)];
            $nextPage = (int) $response->header('X-Next-Page');
            $page = $nextPage > $page ? $nextPage : 0;
        } while ($page > 0 && $page <= 10);

        return $items;
    }

    private function request(Integration $integration): PendingRequest
    {
        $tokens = $this->credentials->get($integration);

        if ($tokens->expiresSoon()) {
            $tokens = Cache::lock('gitlab-oauth-refresh:'.$integration->id, 10)
                ->block(5, function () use ($integration) {
                    $current = $this->credentials->get($integration);

                    if (! $current->expiresSoon()) {
                        return $current;
                    }

                    $refreshed = $this->refresh($current);
                    $this->credentials->put($integration, $refreshed);

                    return $refreshed;
                });
        }

        return $this->requestWithToken(
            $this->apiUrl($integration),
            $tokens->accessToken,
            $tokens->tokenType,
        );
    }

    public function apiUrl(Integration $integration): string
    {
        $apiUrl = data_get($integration->settings, 'gitlab_api_url');

        return rtrim(
            is_string($apiUrl) && $apiUrl !== ''
                ? $apiUrl
                : (string) config('integrations.gitlab.api_url'),
            '/',
        );
    }

    private function requestWithToken(
        string $apiUrl,
        string $token,
        string $tokenType = 'Bearer',
    ): PendingRequest {
        $request = Http::baseUrl(rtrim($apiUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(3);

        return strcasecmp($tokenType, 'Private-Token') === 0
            ? $request->withHeaders(['PRIVATE-TOKEN' => $token])
            : $request->withToken($token);
    }

    private function tokens(Response $response, ?string $fallbackRefreshToken = null): OAuthTokens
    {
        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('GitLab did not return an OAuth access token.');
        }

        $refreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        $createdAt = $response->json('created_at');
        $scope = $response->json('scope');
        $scopes = is_string($scope)
            ? preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY)
            : (is_array($scope) ? array_values($scope) : $this->configuredScopes());

        return new OAuthTokens(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== ''
                ? $refreshToken
                : $fallbackRefreshToken,
            tokenType: (string) ($response->json('token_type') ?: 'Bearer'),
            scopes: $scopes,
            expiresAt: is_numeric($expiresIn) && (int) $expiresIn > 0
                ? (new DateTimeImmutable)->setTimestamp(
                    (is_numeric($createdAt) ? (int) $createdAt : time()) + (int) $expiresIn,
                )
                : null,
        );
    }

    /** @return list<string> */
    private function configuredScopes(): array
    {
        $scopes = config('integrations.gitlab.scopes', ['api']);

        return is_array($scopes)
            ? array_values(array_filter($scopes, fn ($scope) => is_string($scope) && $scope !== ''))
            : ['api'];
    }

    private function redirectUri(): string
    {
        return route('integrations.gitlab.callback');
    }

    private function assertOAuthConfigured(): void
    {
        if ((string) config('integrations.gitlab.client_id') === ''
            || (string) config('integrations.gitlab.client_secret') === '') {
            throw new RuntimeException('GitLab OAuth client ID and client secret must be configured.');
        }
    }
}
