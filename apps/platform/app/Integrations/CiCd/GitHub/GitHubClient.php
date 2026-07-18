<?php

namespace App\Integrations\CiCd\GitHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubClient
{
    public function __construct(private readonly GitHubAppJwtFactory $jwtFactory) {}

    public function installationUrl(string $state): string
    {
        $slug = (string) config('integrations.github.slug');
        $clientId = (string) config('integrations.github.client_id');
        $clientSecret = (string) config('integrations.github.client_secret');

        if ($slug === '') {
            throw new RuntimeException('GitHub App slug must be configured.');
        }

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GitHub App client ID and client secret must be configured.');
        }

        return rtrim((string) config('integrations.github.web_url'), '/')
            .'/apps/'.rawurlencode($slug).'/installations/new?'.http_build_query(['state' => $state]);
    }

    public function exchangeUserCode(string $code): string
    {
        $response = Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->post(rtrim((string) config('integrations.github.web_url'), '/').'/login/oauth/access_token', [
                'client_id' => config('integrations.github.client_id'),
                'client_secret' => config('integrations.github.client_secret'),
                'code' => $code,
            ])
            ->throw();
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            $error = $response->json('error_description') ?: $response->json('error');

            throw new RuntimeException(is_string($error) && $error !== ''
                ? 'GitHub user authorization failed: '.$error
                : 'GitHub did not return a user access token.');
        }

        return $token;
    }

    /** @return list<array<string, mixed>> */
    public function userInstallations(string $userToken): array
    {
        $response = $this->request($userToken)->get('/user/installations', ['per_page' => 100])->throw();

        return array_values($response->json('installations', []));
    }

    /** @return array<string, mixed> */
    public function installation(string $installationId): array
    {
        return $this->appRequest()
            ->get('/app/installations/'.rawurlencode($installationId))
            ->throw()
            ->json();
    }

    public function installationToken(string $installationId): string
    {
        $response = $this->appRequest()
            ->post('/app/installations/'.rawurlencode($installationId).'/access_tokens')
            ->throw();
        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GitHub did not return an installation access token.');
        }

        return $token;
    }

    /** @return list<array<string, mixed>> */
    public function repositories(string $installationId): array
    {
        $response = $this->installationRequest($installationId)
            ->get('/installation/repositories', ['per_page' => 100])
            ->throw();

        return array_values($response->json('repositories', []));
    }

    /** @return list<array<string, mixed>> */
    public function workflows(string $installationId, string $repository): array
    {
        $response = $this->installationRequest($installationId)
            ->get('/repos/'.$repository.'/actions/workflows', ['per_page' => 100])
            ->throw();

        return array_values($response->json('workflows', []));
    }

    /** @return list<array<string, mixed>> */
    public function branches(string $installationId, string $repository): array
    {
        return array_values($this->installationRequest($installationId)
            ->get('/repos/'.$repository.'/branches', ['per_page' => 100])
            ->throw()
            ->json());
    }

    /** @return list<array<string, mixed>> */
    public function tags(string $installationId, string $repository): array
    {
        return array_values($this->installationRequest($installationId)
            ->get('/repos/'.$repository.'/tags', ['per_page' => 100])
            ->throw()
            ->json());
    }

    public function workflowContents(
        string $installationId,
        string $repository,
        string $path,
        string $ref,
    ): string {
        $response = $this->installationRequest($installationId)
            ->get('/repos/'.$repository.'/contents/'.ltrim($path, '/'), ['ref' => $ref])
            ->throw();
        $contents = $response->json('content');

        if (! is_string($contents)) {
            throw new RuntimeException('GitHub workflow contents are missing.');
        }

        $decoded = base64_decode(str_replace("\n", '', $contents), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('GitHub workflow contents are not valid base64.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $inputs */
    public function dispatchWorkflow(
        string $installationId,
        string $repository,
        string $workflowId,
        string $ref,
        array $inputs,
    ): array {
        return $this->installationRequest($installationId)
            ->post('/repos/'.$repository.'/actions/workflows/'.rawurlencode($workflowId).'/dispatches', [
                'ref' => $ref,
                'inputs' => $inputs,
            ])
            ->throw()
            ->json();
    }

    public function cancelWorkflowRun(string $installationId, string $repository, string $runId): int
    {
        return $this->installationRequest($installationId)
            ->post('/repos/'.$repository.'/actions/runs/'.rawurlencode($runId).'/cancel')
            ->status();
    }

    /** @return array<string, mixed> */
    public function workflowRun(string $installationId, string $repository, string $runId): array
    {
        return $this->installationRequest($installationId)
            ->get('/repos/'.$repository.'/actions/runs/'.rawurlencode($runId))
            ->throw()
            ->json();
    }

    private function appRequest(): PendingRequest
    {
        return $this->request($this->jwtFactory->make());
    }

    private function installationRequest(string $installationId): PendingRequest
    {
        return $this->request($this->installationToken($installationId));
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('integrations.github.api_url'), '/'))
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => config('integrations.github.api_version')])
            ->withToken($token)
            ->timeout(15)
            ->connectTimeout(3);
    }
}
