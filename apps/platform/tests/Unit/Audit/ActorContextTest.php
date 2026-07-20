<?php

namespace Tests\Unit\Audit;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\Audit\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActorContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_context_has_no_originator(): void
    {
        $ctx = ActorContext::system();

        $this->assertNull($ctx->originator);
        $this->assertSame('system', $ctx->source);
        $this->assertSame('system', $ctx->actorType());
        $this->assertSame(['type' => 'system'], $ctx->toWebhookActor());
    }

    public function test_user_context_webhook_actor_omits_email(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $ctx = ActorContext::forUser($user, 'web', null, '10.0.0.1', 'agent');

        $this->assertSame('user', $ctx->actorType());
        $this->assertSame(
            ['type' => 'user', 'id' => $user->id, 'display_name' => 'Ada', 'source' => 'web'],
            $ctx->toWebhookActor(),
        );
    }

    public function test_api_key_context_reports_api_key_type(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $key = ApiKey::factory()->create(['name' => 'CI Deploy', 'tokenable_id' => $user->id, 'tokenable_type' => User::class]);
        $ctx = ActorContext::forUser($user, 'api', $key, null, null);

        $this->assertSame('api_key', $ctx->actorType());
        $actor = $ctx->toWebhookActor();
        $this->assertSame('api_key', $actor['type']);
        $this->assertSame($key->id, $actor['id']);
        $this->assertSame('CI Deploy', $actor['display_name']);
    }

    public function test_round_trips_through_array(): void
    {
        $user = User::factory()->create();
        $ctx = ActorContext::forUser($user, 'ci_cd');

        $restored = ActorContext::fromArray($ctx->toArray());

        $this->assertSame($user->id, $restored->originator?->id);
        $this->assertSame('ci_cd', $restored->source);
    }
}
