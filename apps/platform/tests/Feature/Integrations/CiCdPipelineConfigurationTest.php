<?php

namespace Tests\Feature\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\AppIdentifier;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdPipelineConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_configures_only_discovered_dispatchable_pipeline(): void
    {
        [$owner, $team, $app, $identifier, $integration] = $this->context(TeamRole::Owner);
        $this->fakeProvider();

        $this->actingAs($owner)->post(route('accounts.integrations.pipelines.store', [$team, $integration]), [
            'name' => 'Android production',
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::Production->value,
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
            'input_defaults' => ['channel' => 'internal', 'dry_run' => false],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Pipeline configured.');

        $pipeline = CiCdPipeline::firstOrFail();
        $this->assertSame('100', $pipeline->repository_id);
        $this->assertSame('.github/workflows/release.yml', $pipeline->workflow_path);
        $this->assertSame('internal', $pipeline->input_defaults['channel']);
        $this->assertSame($identifier->id, $pipeline->app_identifier_id);
    }

    public function test_unavailable_repository_workflow_or_ref_is_rejected(): void
    {
        [$owner, $team, $app, $identifier, $integration] = $this->context(TeamRole::Owner);
        $this->fakeProvider();
        $base = [
            'name' => 'Android production',
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::Production->value,
            'repository_full_name' => 'foreign/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ];

        $this->actingAs($owner)
            ->post(route('accounts.integrations.pipelines.store', [$team, $integration]), $base)
            ->assertSessionHasErrors('repository_full_name');

        $base['repository_full_name'] = 'octo/mobile';
        $base['workflow_id'] = 'missing';
        $this->post(route('accounts.integrations.pipelines.store', [$team, $integration]), $base)
            ->assertSessionHasErrors('workflow_id');

        $base['workflow_id'] = '200';
        $base['default_ref'] = 'missing';
        $this->post(route('accounts.integrations.pipelines.store', [$team, $integration]), $base)
            ->assertSessionHasErrors('default_ref');

        $this->assertDatabaseCount('ci_cd_pipelines', 0);
    }

    public function test_manual_reporting_requires_a_declared_string_build_id_input(): void
    {
        [$owner, $team, $app, $identifier, $integration] = $this->context(TeamRole::Owner);
        $this->fakeProvider();
        $url = route('accounts.integrations.pipelines.store', [$team, $integration]);
        $base = [
            'name' => 'Self-hosted release',
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::Production->value,
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
            'reporting_mode' => BuildReportingMode::Manual->value,
        ];

        $this->actingAs($owner)->post($url, [
            ...$base,
            'build_id_input' => 'missing',
        ])->assertSessionHasErrors('build_id_input');

        $this->post($url, [
            ...$base,
            'build_id_input' => 'app_manager_build_id',
            'input_defaults' => ['app_manager_build_id' => 'must-be-replaced'],
        ])->assertRedirect();

        $pipeline = CiCdPipeline::firstOrFail();
        $this->assertSame(BuildReportingMode::Manual, $pipeline->reporting_mode);
        $this->assertSame('app_manager_build_id', $pipeline->build_id_input);
        $this->assertArrayNotHasKey('app_manager_build_id', $pipeline->input_defaults);
    }

    public function test_secret_like_or_wrongly_typed_inputs_are_rejected(): void
    {
        [$owner, $team, $app, $identifier, $integration] = $this->context(TeamRole::Owner);
        $this->fakeProvider(includeSecretInput: true);
        $base = [
            'name' => 'Android production',
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::Production->value,
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ];

        $this->actingAs($owner)->post(route('accounts.integrations.pipelines.store', [$team, $integration]), [
            ...$base,
            'input_defaults' => ['deploy_token' => 'plain-secret'],
        ])->assertSessionHasErrors('input_defaults.deploy_token');

        $this->post(route('accounts.integrations.pipelines.store', [$team, $integration]), [
            ...$base,
            'input_defaults' => ['dry_run' => 'not-a-boolean'],
        ])->assertSessionHasErrors('input_defaults.dry_run');
    }

    public function test_admin_can_configure_but_contributor_cannot_enumerate_or_store(): void
    {
        [$admin, $team, $app, $identifier, $integration] = $this->context(TeamRole::Admin);
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $this->fakeProvider();

        $this->actingAs($admin)
            ->getJson(route('accounts.integrations.repositories.index', [$team, $integration]))
            ->assertOk()
            ->assertJsonPath('data.0.fullName', 'octo/mobile');
        $this->actingAs($contributor)
            ->getJson(route('accounts.integrations.repositories.index', [$team, $integration]))
            ->assertForbidden();
        $this->post(route('accounts.integrations.pipelines.store', [$team, $integration]), [
            'name' => 'Forbidden',
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::Production->value,
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ])->assertForbidden();
    }

    private function context(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);

        return [$user, $team, $app, $identifier, $integration];
    }

    private function fakeProvider(bool $includeSecretInput = false): void
    {
        $provider = new class($includeSecretInput) implements CiCdProvider
        {
            public function __construct(private readonly bool $includeSecretInput) {}

            public function provider(): IntegrationProvider
            {
                return IntegrationProvider::GitHub;
            }

            public function repositories(Integration $integration): array
            {
                return [new RepositoryData('100', 'mobile', 'octo/mobile', 'main', true, 'https://github.test/octo/mobile')];
            }

            public function pipelines(Integration $integration, RepositoryData $repository): array
            {
                $inputs = [
                    'channel' => ['description' => null, 'required' => false, 'default' => 'internal', 'type' => 'choice', 'options' => ['internal', 'production']],
                    'dry_run' => ['description' => null, 'required' => false, 'default' => false, 'type' => 'boolean', 'options' => []],
                    'app_manager_build_id' => ['description' => null, 'required' => false, 'default' => null, 'type' => 'string', 'options' => []],
                ];

                if ($this->includeSecretInput) {
                    $inputs['deploy_token'] = ['description' => null, 'required' => false, 'default' => null, 'type' => 'string', 'options' => []];
                }

                return [new PipelineData('200', 'Release', '.github/workflows/release.yml', 'active', 'https://github.test/actions/200', $inputs)];
            }

            public function refs(Integration $integration, RepositoryData $repository): array
            {
                return [new RefData('main', 'branch', str_repeat('a', 40))];
            }

            public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
            {
                return new DispatchResult('1', 'https://api.github.test/runs/1', 'https://github.test/runs/1');
            }

            public function cancel(CiCdRun $run): CancelResult
            {
                return new CancelResult(true);
            }

            public function status(CiCdRun $run): ProviderRunStatus
            {
                return new ProviderRunStatus('1', 'completed', 'success', 'https://github.test/runs/1');
            }

            public function logs(CiCdRun $run): string
            {
                return 'run logs';
            }
        };

        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$provider]));
    }
}
