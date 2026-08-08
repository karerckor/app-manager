<?php

namespace App\Integrations\Expo;

use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExpoClient
{
    public function __construct(private readonly OAuthCredentialStore $credentials) {}

    /** @return array<string, mixed> */
    public function actorWithToken(string $token): array
    {
        $data = $this->requestWithToken($token, <<<'GRAPHQL'
            query AppManagerViewer {
              meActor {
                id
                displayName
                accounts {
                  id
                  name
                  displayName
                }
              }
            }
            GRAPHQL);

        return (array) data_get($data, 'meActor', []);
    }

    /** @return array<string, mixed> */
    public function accountByNameWithToken(string $token, string $accountName): array
    {
        $data = $this->requestWithToken($token, <<<'GRAPHQL'
            query AppManagerExpoAccountByName($accountName: String!) {
              account {
                byName(accountName: $accountName) {
                  id
                  name
                  displayName
                }
              }
            }
            GRAPHQL, ['accountName' => $accountName]);

        return (array) data_get($data, 'account.byName', []);
    }

    /** @return array<string, mixed> */
    public function account(Integration $integration): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoAccount($accountName: String!) {
              account {
                byName(accountName: $accountName) {
                  id
                  name
                  displayName
                }
              }
            }
            GRAPHQL, ['accountName' => $integration->external_account_name]);

        return (array) data_get($data, 'account.byName', []);
    }

    /** @return array<int, array<string, mixed>> */
    public function projects(Integration $integration): array
    {
        $projects = [];
        $offset = 0;
        $limit = 50;

        do {
            $data = $this->request($integration, <<<'GRAPHQL'
                query AppManagerExpoProjects($accountName: String!, $limit: Int!, $offset: Int!) {
                  account {
                    byName(accountName: $accountName) {
                      apps(includeUnpublished: true, limit: $limit, offset: $offset) {
                        id
                        name
                        slug
                        fullName
                        description
                        profileImageUrl(width: EXTRA_LARGE)
                        ownerAccount {
                          id
                          name
                        }
                      }
                    }
                  }
                }
                GRAPHQL, [
                'accountName' => $integration->external_account_name,
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $page = (array) data_get($data, 'account.byName.apps', []);
            array_push($projects, ...$page);
            $offset += count($page);
        } while (count($page) === $limit);

        return $projects;
    }

    /** @return array<string, mixed> */
    public function createProject(Integration $integration, string $slug, string $displayName): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerCreateExpoProject($input: AppInput!) {
              app {
                createApp(appInput: $input) {
                  id
                  name
                  slug
                  fullName
                  description
                  profileImageUrl(width: EXTRA_LARGE)
                  updated
                  ownerAccount {
                    id
                    name
                  }
                }
              }
            }
            GRAPHQL, [
            'input' => [
                'accountId' => $integration->external_account_id,
                'projectName' => $slug,
                'appInfo' => ['displayName' => $displayName],
            ],
        ]);

        return (array) data_get($data, 'app.createApp', []);
    }

    /** @return array<string, mixed> */
    public function updateProjectInfo(
        Integration $integration,
        string $appId,
        string $displayName,
    ): array {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerSetExpoProjectInfo($appId: ID!, $appInfo: AppInfoInput!) {
              app {
                setAppInfo(appId: $appId, appInfo: $appInfo) {
                  id
                  name
                  slug
                  profileImageUrl
                }
              }
            }
            GRAPHQL, [
            'appId' => $appId,
            'appInfo' => ['displayName' => $displayName],
        ]);

        return (array) data_get($data, 'app.setAppInfo', []);
    }

    /** @return array<string, mixed> */
    public function projectSummary(Integration $integration, string $appId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoProjectSummary($appId: String!) {
              app {
                byId(appId: $appId) {
                  id
                  name
                  slug
                  fullName
                  profileImageUrl(width: EXTRA_LARGE)
                }
              }
            }
            GRAPHQL, ['appId' => $appId]);

        return (array) data_get($data, 'app.byId', []);
    }

    /** @return array<string, mixed> */
    public function uploadProjectIcon(
        Integration $integration,
        string $appId,
        string $contents,
        string $mimeType,
    ): array {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerCreateExpoProjectIconUpload($appId: ID!) {
              uploadSession {
                createAppScopedUploadSession(appID: $appId, type: PROFILE_IMAGE_UPLOAD)
              }
            }
            GRAPHQL, ['appId' => $appId]);
        $session = (array) data_get(
            $data,
            'uploadSession.createAppScopedUploadSession',
            [],
        );
        $url = (string) ($session['url'] ?? '');
        $headers = $session['headers'] ?? [];
        if (is_string($headers)) {
            $headers = json_decode($headers, true) ?: [];
        }

        if ($url === '' || ! is_array($headers)) {
            throw new ExpoGraphqlException('Expo did not return a valid icon upload session.');
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($contents, $mimeType)
                ->timeout(max(1, (int) config('integrations.expo.timeout_seconds', 30)))
                ->put($url);
        } catch (ConnectionException) {
            throw new ExpoGraphqlException('The Expo icon upload could not be reached.', status: 503);
        }

        if ($response->failed()) {
            throw new ExpoGraphqlException(
                'Expo rejected the project icon upload (HTTP '.$response->status().').',
                status: $response->status(),
            );
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public function removeProjectIcon(Integration $integration, string $appId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerRemoveExpoProjectIcon($appId: ID!) {
              app {
                removeProfileImage(appId: $appId) {
                  id
                  name
                  profileImageUrl
                }
              }
            }
            GRAPHQL, ['appId' => $appId]);

        return (array) data_get($data, 'app.removeProfileImage', []);
    }

    /** @return array<string, mixed> */
    public function project(
        Integration $integration,
        string $appId,
        int $buildLimit = 30,
        int $buildOffset = 0,
        int $submissionLimit = 30,
        int $submissionOffset = 0,
    ): array {
        return $this->projectData(
            $integration,
            $appId,
            includeCredentialSecrets: false,
            buildLimit: $buildLimit,
            buildOffset: $buildOffset,
            submissionLimit: $submissionLimit,
            submissionOffset: $submissionOffset,
        );
    }

    /** @return array<string, mixed> */
    public function projectWithCredentialSecrets(Integration $integration, string $appId): array
    {
        return $this->projectData($integration, $appId, includeCredentialSecrets: true);
    }

    /** @return array<string, mixed> */
    public function projectIdentifiers(Integration $integration, string $appId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoProjectIdentifiers($appId: String!) {
              app {
                byId(appId: $appId) {
                  id
                  androidAppCredentials {
                    id
                    applicationIdentifier
                  }
                  iosAppCredentials {
                    id
                    appleAppIdentifier {
                      id
                      bundleIdentifier
                    }
                  }
                  builds(limit: 100, offset: 0) {
                    platform
                    appIdentifier
                  }
                }
              }
            }
            GRAPHQL, ['appId' => $appId]);

        return (array) data_get($data, 'app.byId', []);
    }

    /** @return array<string, mixed> */
    public function workflowConfiguration(Integration $integration, string $appId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoWorkflowConfiguration($appId: String!) {
              app {
                byId(appId: $appId) {
                  id
                  name
                  slug
                  fullName
                  ownerAccount {
                    id
                    name
                  }
                  githubRepository {
                    id
                    githubRepositoryUrl
                    metadata {
                      defaultBranch
                      githubRepoName
                      githubRepoOwnerName
                      githubRepoUrl
                      private
                    }
                  }
                  workflows {
                    id
                    name
                    fileName
                    revisionsPaginated(last: 1) {
                      edges {
                        node {
                          id
                          commitSha
                          yamlConfig
                        }
                      }
                    }
                  }
                  workflowRunGitBranchesPaginated(first: 100) {
                    edges {
                      node {
                        name
                        lastRunAt
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL, ['appId' => $appId]);

        return (array) data_get($data, 'app.byId', []);
    }

    /** @return array<string, mixed> */
    private function projectData(
        Integration $integration,
        string $appId,
        bool $includeCredentialSecrets,
        int $buildLimit = 30,
        int $buildOffset = 0,
        int $submissionLimit = 30,
        int $submissionOffset = 0,
    ): array {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoProject(
              $appId: String!
              $submissionFilter: SubmissionFilter!
              $includeCredentialSecrets: Boolean!
              $buildLimit: Int!
              $buildOffset: Int!
              $submissionLimit: Int!
              $submissionOffset: Int!
            ) {
              app {
                byId(appId: $appId) {
                  id
                  name
                  slug
                  fullName
                  description
                  profileImageUrl(width: EXTRA_LARGE)
                  updated
                  buildProfiles
                  ownerAccount {
                    id
                    name
                  }
                  githubRepository {
                    id
                    githubRepositoryUrl
                    metadata {
                      defaultBranch
                      githubRepoName
                      githubRepoOwnerName
                      githubRepoUrl
                      private
                    }
                  }
                  workflows {
                    id
                    name
                    fileName
                  }
                  androidAppCredentials {
                    id
                    applicationIdentifier
                    googleServiceAccountKeyForFcmV1 {
                      id
                      clientEmail
                      projectIdentifier
                      updatedAt
                      keyJson @include(if: $includeCredentialSecrets)
                    }
                    googleServiceAccountKeyForSubmissions {
                      id
                      clientEmail
                      projectIdentifier
                      updatedAt
                      keyJson @include(if: $includeCredentialSecrets)
                    }
                    androidAppBuildCredentialsList {
                      id
                      name
                      isDefault
                      androidKeystore {
                        id
                        keyAlias
                        md5CertificateFingerprint
                        sha1CertificateFingerprint
                        sha256CertificateFingerprint
                        updatedAt
                        keystore @include(if: $includeCredentialSecrets)
                        keystorePassword @include(if: $includeCredentialSecrets)
                        keyPassword @include(if: $includeCredentialSecrets)
                      }
                    }
                  }
                  iosAppCredentials {
                    id
                    appleAppIdentifier {
                      id
                      bundleIdentifier
                    }
                    appleTeam {
                      id
                      appleTeamIdentifier
                      appleTeamName
                    }
                    pushKey {
                      id
                      keyIdentifier
                      updatedAt
                      appleTeam {
                        id
                        appleTeamIdentifier
                        appleTeamName
                      }
                      keyP8 @include(if: $includeCredentialSecrets)
                    }
                    appStoreConnectApiKeyForSubmissions {
                      id
                      name
                      keyIdentifier
                      issuerIdentifier
                      updatedAt
                      keyP8 @include(if: $includeCredentialSecrets)
                    }
                    iosAppBuildCredentialsList {
                      id
                      iosDistributionType
                      distributionCertificate {
                        id
                        serialNumber
                        validityNotAfter
                        updatedAt
                        certificateP12 @include(if: $includeCredentialSecrets)
                        certificatePassword @include(if: $includeCredentialSecrets)
                      }
                      provisioningProfile {
                        id
                        appleUUID
                        expiration
                        status
                        updatedAt
                        provisioningProfile @include(if: $includeCredentialSecrets)
                      }
                    }
                  }
                  builds(limit: $buildLimit, offset: $buildOffset) {
                    id
                    platform
                    status
                    buildProfile
                    distribution
                    appIdentifier
                    appVersion
                    appBuildVersion
                    gitRef
                    gitCommitHash
                    message
                    createdAt
                    updatedAt
                    completedAt
                    workerStartedAt
                    canRetry
                    error {
                      errorCode
                      message
                    }
                    artifacts {
                      applicationArchiveUrl
                      buildUrl
                    }
                  }
                  submissions(
                    filter: $submissionFilter
                    limit: $submissionLimit
                    offset: $submissionOffset
                  ) {
                    id
                    platform
                    status
                    archiveUrl
                    canRetry
                    createdAt
                    updatedAt
                    completedAt
                    error {
                      errorCode
                      message
                    }
                    submittedBuild {
                      id
                    }
                    androidConfig {
                      applicationIdentifier
                      track
                    }
                    iosConfig {
                      ascAppIdentifier
                    }
                  }
                }
              }
            }
            GRAPHQL, [
            'appId' => $appId,
            'submissionFilter' => (object) [],
            'includeCredentialSecrets' => $includeCredentialSecrets,
            'buildLimit' => $buildLimit,
            'buildOffset' => $buildOffset,
            'submissionLimit' => $submissionLimit,
            'submissionOffset' => $submissionOffset,
        ]);

        return (array) data_get($data, 'app.byId', []);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function dispatchWorkflow(
        Integration $integration,
        string $appId,
        string $gitRef,
        string $fileName,
        array $inputs,
    ): array {
        return $this->restRequest($integration, 'POST', '/v2/workflows/dispatch', [
            'appId' => $appId,
            'gitRef' => $gitRef,
            'fileName' => $fileName,
            'inputs' => (object) $inputs,
        ]);
    }

    /** @return array<string, mixed> */
    public function workflowRun(Integration $integration, string $workflowRunId): array
    {
        return $this->restRequest(
            $integration,
            'GET',
            '/v2/workflows/runs/'.rawurlencode($workflowRunId),
        );
    }

    /** @return array<string, mixed> */
    public function cancelWorkflowRun(Integration $integration, string $workflowRunId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerCancelExpoWorkflowRun($workflowRunId: ID!) {
              workflowRun {
                cancelWorkflowRun(workflowRunId: $workflowRunId) {
                  id
                  status
                  updatedAt
                }
              }
            }
            GRAPHQL, ['workflowRunId' => $workflowRunId]);

        return (array) data_get($data, 'workflowRun.cancelWorkflowRun', []);
    }

    /** @return array<string, mixed> */
    public function easBuild(Integration $integration, string $buildId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoBuild($buildId: ID!) {
              builds {
                byId(buildId: $buildId) {
                  id
                  platform
                  status
                  buildProfile
                  distribution
                  appIdentifier
                  appVersion
                  appBuildVersion
                  gitRef
                  gitCommitHash
                  message
                  createdAt
                  updatedAt
                  completedAt
                  workerStartedAt
                  canRetry
                  error {
                    errorCode
                    message
                  }
                  artifacts {
                    applicationArchiveUrl
                    buildUrl
                  }
                  project {
                    id
                    name
                    slug
                    fullName
                    ... on App {
                      ownerAccount {
                        id
                        name
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL, ['buildId' => $buildId]);

        return (array) data_get($data, 'builds.byId', []);
    }

    public function easBuildLogs(Integration $integration, string $buildId): string
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            query AppManagerExpoBuildLogFiles($buildId: ID!) {
              builds {
                byId(buildId: $buildId) {
                  id
                  logFiles
                }
              }
            }
            GRAPHQL, ['buildId' => $buildId]);

        $logFiles = array_values(array_filter(
            (array) data_get($data, 'builds.byId.logFiles', []),
            fn ($url) => is_string($url) && $url !== '',
        ));

        $sections = [];

        foreach ($logFiles as $url) {
            // Log files live behind pre-signed storage URLs; an Authorization
            // header would make signed-URL requests fail.
            try {
                $response = Http::withUserAgent('App Manager Expo integration')
                    ->timeout(max(1, (int) config('integrations.expo.timeout_seconds', 30)))
                    ->get($url);
            } catch (ConnectionException) {
                continue;
            }

            if ($response->failed()) {
                continue;
            }

            $content = $this->formatEasLogContent(trim($response->body()));

            if ($content !== '') {
                $sections[] = $content;
            }
        }

        return implode("\n", $sections);
    }

    /**
     * EAS log files are newline-delimited JSON entries; surface the human
     * readable message of each entry and keep unrecognized lines verbatim.
     */
    private function formatEasLogContent(string $content): string
    {
        $lines = [];

        foreach (preg_split("/\r?\n/", $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            $lines[] = is_array($entry) && is_string($entry['msg'] ?? null)
                ? rtrim($entry['msg'])
                : $line;
        }

        return implode("\n", $lines);
    }

    public function downloadFile(Integration $integration, string $url, string $destinationPath): bool
    {
        try {
            // Artifacts can be hundreds of megabytes; stream the body to disk
            // instead of buffering it in memory.
            $response = Http::withToken($this->credentials->get($integration)->accessToken)
                ->withUserAgent('App Manager Expo integration')
                ->timeout(300)
                ->withOptions(['allow_redirects' => ['max' => 5], 'stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('EAS artifact download connection failed', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('EAS artifact download HTTP error', [
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            return false;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_starts_with($contentType, 'text/html') || str_starts_with($contentType, 'application/json')) {
            Log::warning('EAS artifact URL returned unexpected content type', [
                'url' => $url,
                'content_type' => $contentType,
            ]);
        }

        $dir = dirname($destinationPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $source = $response->toPsrResponse()->getBody();
        $destination = fopen($destinationPath, 'wb');

        if ($destination === false) {
            Log::warning('EAS artifact file write failed', [
                'url' => $url,
                'destination' => $destinationPath,
            ]);

            return false;
        }

        try {
            while (! $source->eof()) {
                fwrite($destination, $source->read(1024 * 1024));
            }
        } finally {
            fclose($destination);
            $source->close();
        }

        $written = filesize($destinationPath);

        if ($written === false || $written === 0) {
            Log::warning('EAS artifact file write failed', [
                'url' => $url,
                'destination' => $destinationPath,
            ]);

            @unlink($destinationPath);

            return false;
        }

        Log::info('EAS artifact downloaded', [
            'url' => $url,
            'destination' => $destinationPath,
            'size' => $written,
        ]);

        return true;
    }

    /** @return array<string, mixed> */
    public function cancelBuild(Integration $integration, string $buildId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerCancelExpoBuild($buildId: ID!) {
              build {
                cancelBuild(buildId: $buildId) {
                  id
                  status
                  updatedAt
                }
              }
            }
            GRAPHQL, ['buildId' => $buildId]);

        return (array) data_get($data, 'build.cancelBuild', []);
    }

    /** @return array<string, mixed> */
    public function retryBuild(Integration $integration, string $buildId, string $platform): array
    {
        $field = strtoupper($platform) === 'IOS' ? 'retryIosBuild' : 'retryAndroidBuild';
        $data = $this->request($integration, sprintf(<<<'GRAPHQL'
            mutation AppManagerRetryExpoBuild($buildId: ID!) {
              build {
                %s(buildId: $buildId) {
                  id
                  platform
                  status
                  createdAt
                }
              }
            }
            GRAPHQL, $field), ['buildId' => $buildId]);

        return (array) data_get($data, "build.{$field}", []);
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function createSubmission(
        Integration $integration,
        string $appId,
        string $buildId,
        string $platform,
        array $config,
    ): array {
        $field = strtoupper($platform) === 'IOS'
            ? 'createIosSubmission'
            : 'createAndroidSubmission';
        $inputType = strtoupper($platform) === 'IOS'
            ? 'CreateIosSubmissionInput!'
            : 'CreateAndroidSubmissionInput!';
        $data = $this->request($integration, sprintf(<<<'GRAPHQL'
            mutation AppManagerCreateExpoSubmission($input: %s) {
              submission {
                %s(input: $input) {
                  submission {
                    id
                    platform
                    status
                    createdAt
                  }
                }
              }
            }
            GRAPHQL, $inputType, $field), [
            'input' => [
                'appId' => $appId,
                'submittedBuildId' => $buildId,
                'config' => $config,
            ],
        ]);

        return (array) data_get($data, "submission.{$field}.submission", []);
    }

    /** @return array<string, mixed> */
    public function cancelSubmission(Integration $integration, string $submissionId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerCancelExpoSubmission($submissionId: ID!) {
              submission {
                cancelSubmission(submissionId: $submissionId) {
                  id
                  status
                  updatedAt
                }
              }
            }
            GRAPHQL, ['submissionId' => $submissionId]);

        return (array) data_get($data, 'submission.cancelSubmission', []);
    }

    /** @return array<string, mixed> */
    public function retrySubmission(Integration $integration, string $submissionId): array
    {
        $data = $this->request($integration, <<<'GRAPHQL'
            mutation AppManagerRetryExpoSubmission($submissionId: ID!) {
              submission {
                retrySubmission(parentSubmissionId: $submissionId) {
                  submission {
                    id
                    platform
                    status
                    createdAt
                  }
                }
              }
            }
            GRAPHQL, ['submissionId' => $submissionId]);

        return (array) data_get($data, 'submission.retrySubmission.submission', []);
    }

    /** @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function request(
        Integration $integration,
        string $query,
        array $variables = [],
        ?string $operationName = null,
    ): array {
        return $this->requestWithToken(
            $this->credentials->get($integration)->accessToken,
            $query,
            $variables,
            $operationName,
        );
    }

    /** @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function requestWithToken(
        string $token,
        string $query,
        array $variables = [],
        ?string $operationName = null,
    ): array {
        $payload = [
            'query' => $query,
            'variables' => $variables,
        ];
        if ($operationName !== null) {
            $payload['operationName'] = $operationName;
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->withUserAgent('App Manager Expo integration')
                ->timeout(max(1, (int) config('integrations.expo.timeout_seconds', 30)))
                ->post((string) config('integrations.expo.graphql_url'), $payload);
        } catch (ConnectionException $exception) {
            throw new ExpoGraphqlException('Expo could not be reached.', status: 503);
        }

        $payload = $response->json();
        $errors = is_array($payload)
            ? array_values(array_filter(
                (array) ($payload['errors'] ?? []),
                'is_array',
            ))
            : [];

        if ($response->failed()) {
            throw new ExpoGraphqlException(
                $this->httpErrorMessage($response, $errors),
                $errors,
                $response->status(),
            );
        }

        if (! is_array($payload)) {
            throw new ExpoGraphqlException('Expo returned an invalid GraphQL response.');
        }

        if ($errors !== []) {
            throw new ExpoGraphqlException(
                $this->graphqlErrorMessage($errors)
                    ?? 'Expo rejected the GraphQL operation.',
                $errors,
                $response->status(),
            );
        }

        return (array) ($payload['data'] ?? []);
    }

    /**
     * Send an authenticated request to the configured Expo API origin.
     *
     * The caller is responsible for validating the relative path and deciding
     * whether the HTTP method requires read or write authorization.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function proxyRest(
        Integration $integration,
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        array $headers = [],
    ): Response {
        $url = rtrim((string) config('integrations.expo.api_url'), '/')
            .'/'.ltrim($path, '/');

        try {
            $request = Http::withToken($this->credentials->get($integration)->accessToken)
                ->withUserAgent('App Manager Expo proxy')
                ->withHeaders($headers)
                ->timeout(max(1, (int) config('integrations.expo.timeout_seconds', 30)));

            if ($body !== '') {
                $request = $request->withBody(
                    $body,
                    $headers['Content-Type'] ?? 'application/json',
                );
            }

            return $request->send(strtoupper($method), $url, ['query' => $query]);
        } catch (ConnectionException) {
            throw new ExpoGraphqlException('Expo could not be reached.', status: 503);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function restRequest(
        Integration $integration,
        string $method,
        string $path,
        array $payload = [],
    ): array {
        $url = rtrim((string) config('integrations.expo.api_url'), '/').$path;

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withToken($this->credentials->get($integration)->accessToken)
                ->withUserAgent('App Manager Expo integration')
                ->timeout(max(1, (int) config('integrations.expo.timeout_seconds', 30)));
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->post($url, $payload);
        } catch (ConnectionException) {
            throw new ExpoGraphqlException('Expo could not be reached.', status: 503);
        }

        $response->throw();
        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        if (! is_array($data)) {
            throw new RuntimeException('Expo returned an invalid Workflows API response.');
        }

        return $data;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function httpErrorMessage(Response $response, array $errors): string
    {
        if (in_array($response->status(), [401, 403], true)) {
            return 'Expo rejected the access token or its permissions.';
        }

        if ($response->status() === 429) {
            return 'Expo rate limit exceeded. Please try again later.';
        }

        $message = $this->graphqlErrorMessage($errors);

        return $message !== null
            ? "Expo HTTP {$response->status()}: {$message}"
            : "Expo returned HTTP {$response->status()}.";
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function graphqlErrorMessage(array $errors): ?string
    {
        $message = collect($errors)
            ->pluck('message')
            ->filter(fn ($message) => is_string($message))
            ->implode(' ');

        return $message !== '' ? $message : null;
    }
}
