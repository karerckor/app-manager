<?php

namespace Tests\Feature\Audit;

use App\Enums\BuildStatus;
use App\Enums\WebhookEventType;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdActorPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.enabled' => true,
            'integrations.webhooks.enabled' => true,
        ]);
    }

    public function test_build_lifecycle_attributes_ci_cd_source_to_original_requester(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $user->id,
            'status' => BuildStatus::Pending,
        ]);

        app(BuildLifecycle::class)->start($build);

        $event = WebhookEvent::query()->where('event_type', WebhookEventType::BuildStarted)->sole();
        $audit = Audit::query()
            ->where('auditable_type', BuildRecord::class)
            ->where('auditable_id', $build->id)
            ->where('event', 'updated')
            ->sole();

        $this->assertSame('ci_cd', $event->source);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('user', $event->payload['actor']['type']);
        $this->assertSame('ci_cd', $audit->source);
        $this->assertSame($user->id, $audit->user_id);
    }
}
