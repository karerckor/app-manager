<?php

namespace Tests\Feature\Integrations;

use App\Enums\CiCdDispatchStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Integrations\CiCd\GitHub\GitHubProvider;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class GitHubProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_uses_installation_tokens_and_filters_non_dispatchable_workflows(): void
    {
        $integration = $this->integration();
        $this->configureGitHub();
        Http::fake([
            'https://api.github.test/app/installations/42/access_tokens' => Http::response(['token' => 'installation-token']),
            'https://api.github.test/installation/repositories*' => Http::response(['repositories' => [[
                'id' => 100,
                'name' => 'mobile',
                'full_name' => 'octo/mobile',
                'default_branch' => 'main',
                'private' => true,
                'html_url' => 'https://github.test/octo/mobile',
            ]]]),
            'https://api.github.test/repos/octo/mobile/actions/workflows*' => Http::response(['workflows' => [
                ['id' => 200, 'name' => 'Release', 'path' => '.github/workflows/release.yml', 'state' => 'active', 'html_url' => 'https://github.test/workflows/200'],
                ['id' => 201, 'name' => 'Push only', 'path' => '.github/workflows/push.yml', 'state' => 'active', 'html_url' => 'https://github.test/workflows/201'],
            ]]),
            'https://api.github.test/repos/octo/mobile/contents/.github/workflows/release.yml*' => Http::response([
                'content' => base64_encode("on:\n  workflow_dispatch:\n    inputs:\n      channel:\n        type: string\n"),
            ]),
            'https://api.github.test/repos/octo/mobile/contents/.github/workflows/push.yml*' => Http::response([
                'content' => base64_encode("on: [push]\n"),
            ]),
        ]);
        $provider = app(GitHubProvider::class);

        $repository = $provider->repositories($integration)[0];
        $pipelines = $provider->pipelines($integration, $repository);

        $this->assertSame('octo/mobile', $repository->fullName);
        $this->assertCount(1, $pipelines);
        $this->assertSame('200', $pipelines[0]->id);
        $this->assertArrayHasKey('channel', $pipelines[0]->inputs);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/installation/repositories?per_page=100'
            && $request->hasHeader('Authorization', 'Bearer installation-token')
            && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10'));
    }

    public function test_dispatch_requests_and_maps_the_versioned_run_details_response(): void
    {
        $integration = $this->integration();
        $this->configureGitHub();
        $app = MobileApp::factory()->create(['team_id' => $integration->team_id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $pipeline = CiCdPipeline::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [],
            'input_defaults' => [],
        ]);
        Http::fake([
            'https://api.github.test/app/installations/42/access_tokens' => Http::response(['token' => 'installation-token']),
            'https://api.github.test/repos/octo/mobile/actions/workflows/200/dispatches' => Http::response([
                'workflow_run_id' => 9001,
                'run_url' => 'https://api.github.test/repos/octo/mobile/actions/runs/9001',
                'html_url' => 'https://github.test/octo/mobile/actions/runs/9001',
            ]),
        ]);

        $result = app(GitHubProvider::class)->dispatch($pipeline, 'main', ['channel' => 'production']);

        $this->assertSame('9001', $result->runId);
        $this->assertSame('https://github.test/octo/mobile/actions/runs/9001', $result->htmlUrl);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/repos/octo/mobile/actions/workflows/200/dispatches'
            && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10')
            && $request['ref'] === 'main'
            && $request['inputs'] === ['channel' => 'production']);
    }

    public function test_logs_download_and_extract_the_github_workflow_archive(): void
    {
        $integration = $this->integration();
        $this->configureGitHub();
        $app = MobileApp::factory()->create(['team_id' => $integration->team_id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $pipeline = CiCdPipeline::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [],
            'input_defaults' => [],
        ]);
        $build = BuildRecord::factory()->create([
            'team_id' => $integration->team_id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $run = CiCdRun::create([
            'team_id' => $integration->team_id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => '9001',
            'external_run_url' => 'https://github.test/runs/9001',
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'github-provider-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('0_Android demo.txt', "set up job\n\e[32mApp Manager demo build started\e[0m\ncompile: sources\n");
        $zip->addFromString('Android demo/1_Set up job.txt', "set up job\n");
        $zip->addFromString('Android demo/2_Run build.txt', "\e[32mApp Manager demo build started\e[0m\ncompile: sources\n");
        $zip->close();
        $archive = file_get_contents($path);
        unlink($path);
        Http::fake([
            'https://api.github.test/app/installations/42/access_tokens' => Http::response(['token' => 'installation-token']),
            'https://api.github.test/repos/octo/mobile/actions/runs/9001/logs' => Http::response($archive, 200, ['Content-Type' => 'application/zip']),
        ]);

        $logs = app(GitHubProvider::class)->logs($run->load(['integration', 'pipeline']));

        $this->assertStringContainsString('===== 0_Android demo.txt =====', $logs);
        $this->assertStringNotContainsString('===== Android demo/2_Run build.txt =====', $logs);
        $this->assertStringContainsString('App Manager demo build started', $logs);
        $this->assertSame(1, substr_count($logs, 'App Manager demo build started'));
        $this->assertStringNotContainsString("\e[32m", $logs);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/repos/octo/mobile/actions/runs/9001/logs'
            && $request->hasHeader('Authorization', 'Bearer installation-token'));
    }

    private function integration(): Integration
    {
        $team = Team::factory()->create();

        return Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);
    }

    private function configureGitHub(): void
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);
        config()->set([
            'integrations.github.app_id' => '12345',
            'integrations.github.private_key' => $privateKey,
            'integrations.github.api_url' => 'https://api.github.test',
            'integrations.github.api_version' => '2026-03-10',
        ]);
    }
}
