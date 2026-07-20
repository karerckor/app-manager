<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Apps\AppActivityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_includes_actor_and_source_from_audits(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $team = Team::factory()->create(['owner_id' => $user->id]);

        // Isolate: suppress MobileApp's automatic "created" audit so the only
        // audit is the explicit one below (MobileApp is Auditable).
        MobileApp::disableAuditing();
        try {
            $app = MobileApp::factory()->create(['team_id' => $team->id]);
        } finally {
            MobileApp::enableAuditing();
        }

        Audit::create([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => 'updated',
            'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => ['name' => 'Old'], 'new_values' => ['name' => 'New'],
            'team_id' => $team->id, 'source' => 'web',
        ]);

        $feed = AppActivityFeed::for($team, $app);

        $this->assertNotEmpty($feed);
        $this->assertSame('web', $feed[0]['source']);
        $this->assertSame($user->id, $feed[0]['actor']['id']);
        $this->assertSame('Ada', $feed[0]['actor']['name']);
    }

    public function test_build_events_still_appear_without_actor_source_fabrication(): void
    {
        $team = Team::factory()->create();
        MobileApp::disableAuditing();
        try {
            $app = MobileApp::factory()->create(['team_id' => $team->id]);
        } finally {
            MobileApp::enableAuditing();
        }
        BuildRecord::factory()->create([
            'team_id' => $team->id, 'mobile_app_id' => $app->id,
            'app_identifier_id' => null, 'requested_by_user_id' => null,
        ]);

        $feed = AppActivityFeed::for($team, $app);

        $this->assertNotEmpty($feed);
        // a build_created entry exists, with null actor and null source (not fabricated)
        $build = collect($feed)->firstWhere('type', 'build_created');
        $this->assertNotNull($build);
        $this->assertNull($build['actor']);
        $this->assertNull($build['source']);
    }
}
