<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_audits_older_than_retention(): void
    {
        config(['audit.retention_days' => 30]);
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $old = Audit::create([
            'event' => 'created', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $team->id, 'source' => 'system',
        ]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $fresh = Audit::create([
            'event' => 'updated', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $team->id, 'source' => 'system',
        ]);

        $this->artisan('audits:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audits', ['id' => $old->id]);
        $this->assertDatabaseHas('audits', ['id' => $fresh->id]);
    }
}
