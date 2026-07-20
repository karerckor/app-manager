<?php

namespace Tests\Feature\Audit;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookActorTest extends TestCase
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

    public function test_recorded_event_embeds_web_actor_block_and_v2_schema(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::cases()[0],
            $app,
            ['id' => $app->id],
            ActorContext::forUser($user, 'web'),
        );

        $this->assertNotNull($event);
        $this->assertSame(2, $event->schema_version);
        $this->assertSame('web', $event->source);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame([
            'type' => 'user',
            'id' => $user->id,
            'display_name' => 'Ada',
            'source' => 'web',
        ], $event->payload['actor']);
    }

    public function test_system_event_embeds_system_actor(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::cases()[0],
            $app,
            ['id' => $app->id],
            ActorContext::system(),
        );

        $this->assertNotNull($event);
        $this->assertNull($event->actor_user_id);
        $this->assertSame('system', $event->source);
        $this->assertSame(['type' => 'system'], $event->payload['actor']);
    }
}
