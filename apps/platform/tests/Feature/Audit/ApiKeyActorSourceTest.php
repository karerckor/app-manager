<?php

namespace Tests\Feature\Audit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyActorSourceTest extends TestCase
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

    public function test_api_bearer_token_request_records_webhook_event_with_api_source(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);

        $token = $owner->createTeamApiKey($team, 'ci', [ApiKeyScope::AppsWrite->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Api created app'])
            ->assertCreated();

        $event = WebhookEvent::query()->where('event_type', WebhookEventType::MobileAppCreated)->sole();

        $this->assertSame('api', $event->source);
        $this->assertSame($owner->id, $event->actor_user_id);
        // Sanctum's personal-access-token model is swapped for App\Models\ApiKey
        // (see AppServiceProvider), so any bearer-token request resolves a
        // non-null ApiKey and ActorContext::actorType() correctly reports
        // 'api_key' rather than 'user' for these requests.
        $this->assertSame('api_key', $event->payload['actor']['type']);
    }
}
