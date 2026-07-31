<?php

namespace Tests\Feature\Integrations;

use App\Enums\CiCdDispatchStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Integrations\CiCd\GitLab\GitLabProvider;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitLabProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'integrations.gitlab.client_id' => 'gitlab-client',
            'integrations.gitlab.client_secret' => 'gitlab-secret',
            'integrations.gitlab.web_url' => 'https://gitlab.test',
            'integrations.gitlab.api_url' => 'https://gitlab.test/api/v4',
            'integrations.gitlab.scopes' => ['api'],
        ]);
    }

    public function test_discovery_maps_projects_ci_configuration_inputs_and_refs(): void
    {
        $integration = $this->integration();
        Http::fake([
            'https://gitlab.test/api/v4/projects/100' => Http::response([
                'id' => 100,
                'web_url' => 'https://gitlab.test/octo/mobile',
                'ci_config_path' => '.gitlab-ci.yml',
            ]),
            'https://gitlab.test/api/v4/projects/100/repository/files/.gitlab-ci.yml*' => Http::response([
                'content' => base64_encode("spec:\n  inputs:\n    channel:\n      options: [internal, production]\n      default: internal\n---\nrelease:\n  script: ./release\n"),
            ]),
            'https://gitlab.test/api/v4/projects/100/repository/branches*' => Http::response([[
                'name' => 'main',
                'commit' => ['id' => str_repeat('a', 40)],
            ]]),
            'https://gitlab.test/api/v4/projects/100/repository/tags*' => Http::response([[
                'name' => 'v1.0.0',
                'commit' => ['id' => str_repeat('b', 40)],
            ]]),
            'https://gitlab.test/api/v4/projects*' => Http::response([[
                'id' => 100,
                'name' => 'mobile',
                'path_with_namespace' => 'octo/mobile',
                'default_branch' => 'main',
                'visibility' => 'private',
                'web_url' => 'https://gitlab.test/octo/mobile',
            ]]),
        ]);
        $provider = app(GitLabProvider::class);

        $repository = $provider->repositories($integration)[0];
        $pipelines = $provider->pipelines($integration, $repository);
        $refs = $provider->refs($integration, $repository);

        $this->assertSame('octo/mobile', $repository->fullName);
        $this->assertSame('gitlab-ci', $pipelines[0]->id);
        $this->assertSame(['internal', 'production'], $pipelines[0]->inputs['channel']['options']);
        $this->assertSame(['branch', 'tag'], array_column($refs, 'type'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://gitlab.test/api/v4/projects?')
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_dispatch_status_and_cancel_map_gitlab_pipeline_semantics(): void
    {
        $integration = $this->integration();
        $pipeline = $this->pipeline($integration);
        Http::fake([
            'https://gitlab.test/api/v4/projects/100/pipeline' => Http::response([
                'id' => 9001,
                'status' => 'pending',
                'web_url' => 'https://gitlab.test/octo/mobile/-/pipelines/9001',
            ]),
            'https://gitlab.test/api/v4/projects/100/pipelines/9001/cancel' => Http::response([
                'id' => 9001,
                'status' => 'canceled',
            ]),
            'https://gitlab.test/api/v4/projects/100/pipelines/9001' => Http::sequence()
                ->push([
                    'id' => 9001,
                    'status' => 'running',
                    'web_url' => 'https://gitlab.test/octo/mobile/-/pipelines/9001',
                    'started_at' => '2026-07-22T12:00:00Z',
                    'finished_at' => null,
                ])
                ->push(['id' => 9001, 'status' => 'running']),
        ]);

        $dispatch = app(GitLabProvider::class)->dispatch($pipeline, 'main', ['channel' => 'production']);
        $run = $this->ciCdRun($pipeline, $dispatch->runId);
        $status = app(GitLabProvider::class)->status($run->load(['integration', 'pipeline']));
        $cancel = app(GitLabProvider::class)->cancel($run->load(['integration', 'pipeline']));

        $this->assertSame('9001', $dispatch->runId);
        $this->assertSame('in_progress', $status->status);
        $this->assertNull($status->conclusion);
        $this->assertTrue($cancel->accepted);
        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://gitlab.test/api/v4/projects/100/pipeline'
                && $payload['ref'] === 'main'
                && $payload['inputs'] === ['channel' => 'production'];
        });
    }

    public function test_logs_combine_job_traces_and_strip_terminal_codes(): void
    {
        $pipeline = $this->pipeline($this->integration());
        $run = $this->ciCdRun($pipeline, '9001');
        Http::fake([
            'https://gitlab.test/api/v4/projects/100/pipelines/9001/jobs*' => Http::response([
                ['id' => 501, 'name' => 'android', 'stage' => 'build'],
                ['id' => 502, 'name' => 'publish', 'stage' => 'release'],
            ]),
            'https://gitlab.test/api/v4/projects/100/jobs/501/trace' => Http::response("compile\n\e[32mdone\e[0m\n"),
            'https://gitlab.test/api/v4/projects/100/jobs/502/trace' => Http::response("upload\n"),
        ]);

        $logs = app(GitLabProvider::class)->logs($run->load(['integration', 'pipeline']));

        $this->assertStringContainsString('===== android · build =====', $logs);
        $this->assertStringContainsString('===== publish · release =====', $logs);
        $this->assertStringNotContainsString("\e[32m", $logs);
    }

    public function test_expired_oauth_token_is_refreshed_and_rotated_before_api_request(): void
    {
        $integration = $this->integration(expiresAt: now()->subMinute());
        Http::fake([
            'https://gitlab.test/oauth/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 7200,
                'created_at' => now()->timestamp,
            ]),
            'https://gitlab.test/api/v4/projects*' => Http::response([]),
        ]);

        app(GitLabProvider::class)->repositories($integration);

        $credential = IntegrationOAuthCredential::firstOrFail();
        $this->assertSame('rotated-access-token', $credential->access_token);
        $this->assertSame('rotated-refresh-token', $credential->refresh_token);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://gitlab.test/api/v4/projects?')
            && $request->hasHeader('Authorization', 'Bearer rotated-access-token'));
    }

    public function test_self_hosted_requests_and_dispatch_use_the_integration_instance(): void
    {
        $integration = $this->integration(
            settings: [
                'connection_type' => 'self_hosted',
                'gitlab_web_url' => 'https://gitlab.company.test',
                'gitlab_api_url' => 'https://gitlab.company.test/api/v4',
            ],
            tokenType: 'Private-Token',
        );
        $pipeline = $this->pipeline($integration);
        Http::fake([
            'https://gitlab.company.test/api/v4/projects/100/pipeline' => Http::response([
                'id' => 9001,
                'status' => 'pending',
                'web_url' => 'https://gitlab.company.test/company/mobile/-/pipelines/9001',
            ]),
            'https://gitlab.company.test/api/v4/projects*' => Http::response([[
                'id' => 100,
                'name' => 'mobile',
                'path_with_namespace' => 'company/mobile',
                'default_branch' => 'main',
                'visibility' => 'private',
                'web_url' => 'https://gitlab.company.test/company/mobile',
            ]]),
        ]);

        $repositories = app(GitLabProvider::class)->repositories($integration);
        $dispatch = app(GitLabProvider::class)->dispatch($pipeline, 'main', []);

        $this->assertSame('company/mobile', $repositories[0]->fullName);
        $this->assertSame(
            'https://gitlab.company.test/api/v4/projects/100/pipelines/9001',
            $dispatch->apiUrl,
        );
        Http::assertSent(fn (Request $request) => str_starts_with(
            $request->url(),
            'https://gitlab.company.test/api/v4/projects',
        ) && $request->hasHeader('PRIVATE-TOKEN', 'access-token'));
    }

    private function integration(
        $expiresAt = null,
        array $settings = [],
        string $tokenType = 'Bearer',
    ): Integration {
        $team = Team::factory()->create();
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitLab,
            'name' => 'GitLab octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
            'settings' => $settings,
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $integration->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_type' => $tokenType,
            'scopes' => ['api'],
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);

        return $integration;
    }

    private function pipeline(Integration $integration): CiCdPipeline
    {
        $app = MobileApp::factory()->create(['team_id' => $integration->team_id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);

        return CiCdPipeline::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => 'gitlab-ci',
            'workflow_name' => 'GitLab CI',
            'workflow_path' => '.gitlab-ci.yml',
            'default_ref' => 'main',
            'input_schema' => [],
            'input_defaults' => [],
        ]);
    }

    private function ciCdRun(CiCdPipeline $pipeline, string $externalRunId): CiCdRun
    {
        $build = BuildRecord::factory()->create([
            'team_id' => $pipeline->team_id,
            'mobile_app_id' => $pipeline->mobile_app_id,
            'app_identifier_id' => $pipeline->app_identifier_id,
        ]);

        return CiCdRun::create([
            'team_id' => $pipeline->team_id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $pipeline->integration_id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => $externalRunId,
            'external_run_url' => "https://gitlab.test/pipelines/{$externalRunId}",
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
    }
}
