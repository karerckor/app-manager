<?php

namespace Tests\Feature\Integrations;

use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.github.webhook_secret', 'github-webhook-secret');
    }

    public function test_signed_installation_webhook_is_processed_once_and_payload_is_discarded(): void
    {
        $integration = $this->integration();
        $payload = ['action' => 'suspend', 'installation' => ['id' => 42]];

        $this->deliver('delivery-1', 'installation', $payload)->assertAccepted();
        $this->deliver('delivery-1', 'installation', $payload)->assertAccepted();

        $this->assertDatabaseCount('github_webhook_deliveries', 1);
        $this->assertDatabaseHas('github_webhook_deliveries', [
            'delivery_id' => 'delivery-1',
            'event' => 'installation',
            'installation_id' => '42',
            'payload' => null,
        ]);
        $this->assertSame(IntegrationStatus::Disabled, $integration->refresh()->status);
        $this->assertNotNull($integration->disabled_at);
    }

    public function test_invalid_signature_is_rejected_before_persistence(): void
    {
        $raw = json_encode(['action' => 'deleted', 'installation' => ['id' => 42]], JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.github'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-invalid',
            'HTTP_X_GITHUB_EVENT' => 'installation',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], $raw)->assertUnauthorized();

        $this->assertDatabaseCount('github_webhook_deliveries', 0);
    }

    public function test_reused_delivery_id_with_different_payload_is_rejected(): void
    {
        $this->integration();
        $this->deliver('delivery-reused', 'installation', [
            'action' => 'suspend',
            'installation' => ['id' => 42],
        ])->assertAccepted();

        $this->deliver('delivery-reused', 'installation', [
            'action' => 'deleted',
            'installation' => ['id' => 42],
        ])->assertConflict();
    }

    public function test_deleted_installation_disconnects_integration_without_deleting_it(): void
    {
        $integration = $this->integration();

        $this->deliver('delivery-delete', 'installation', [
            'action' => 'deleted',
            'installation' => ['id' => 42],
        ])->assertAccepted();

        $this->assertSame(IntegrationStatus::Disconnected, $integration->refresh()->status);
        $this->assertNotNull($integration->disconnected_at);
        $this->assertDatabaseHas('integrations', ['id' => $integration->id]);
    }

    public function test_workflow_run_updates_only_matching_installation_repository_and_run(): void
    {
        $integration = $this->integration();
        $app = MobileApp::factory()->create(['team_id' => $integration->team_id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $pipeline = CiCdPipeline::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'enabled' => true,
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
        CiCdRun::create([
            'team_id' => $integration->team_id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => '9001',
            'external_run_url' => 'https://github.test/runs/9001',
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
        $event = [
            'action' => 'completed',
            'installation' => ['id' => 42],
            'repository' => ['full_name' => 'octo/other'],
            'workflow_run' => [
                'id' => 9001,
                'status' => 'completed',
                'conclusion' => 'success',
                'html_url' => 'https://github.test/runs/9001',
                'updated_at' => now()->toISOString(),
            ],
        ];

        $this->deliver('workflow-wrong-repository', 'workflow_run', $event)->assertAccepted();
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);

        $event['repository']['full_name'] = 'octo/mobile';
        $this->deliver('workflow-matching', 'workflow_run', $event)->assertAccepted();
        $this->assertSame(BuildStatus::Completed, $build->refresh()->status);
    }

    private function deliver(string $deliveryId, string $event, array $payload)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('webhooks.github'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'github-webhook-secret'),
        ], $raw);
    }

    private function integration(): Integration
    {
        $team = Team::factory()->create();

        return Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo-org',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);
    }
}
