<?php

namespace Tests\Feature\Audit;

use App\Enums\CiCdDispatchStatus;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdAuditBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_the_pipeline_requester_on_existing_system_build_updates(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
        ]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $user->id,
        ]);
        CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
        $audit = Audit::create([
            'event' => 'updated',
            'auditable_type' => BuildRecord::class,
            'auditable_id' => $build->id,
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'running'],
            'team_id' => $team->id,
            'source' => 'system',
        ]);

        $migration = require database_path('migrations/2026_07_22_190000_backfill_ci_cd_build_audit_actors.php');
        $migration->up();

        $audit->refresh();
        $this->assertSame(User::class, $audit->user_type);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('ci_cd', $audit->source);
    }
}
