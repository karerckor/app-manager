<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_returns_secret_once_and_rejects_private_url(): void
    {
        [$user, $team] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;
        $event = WebhookEventType::subscribableValues()[0];

        $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'http://127.0.0.1/hook', 'events' => [$event],
        ])->assertUnprocessable();

        // "example.test" (RFC 2606) is guaranteed not to resolve in public DNS, and some local
        // dev setups (e.g. Laravel Herd/Valet) hijack the .test TLD to 127.0.0.1. Bypass DNS
        // resolution for this otherwise-valid URL, matching the pattern used in
        // tests/Feature/Integrations/WebhookOutboxTest.php.
        config(['webhooks.allow_private_addresses' => true]);

        $created = $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'https://example.test/hook', 'events' => [$event],
        ])->assertCreated();

        $created->assertJsonPath('data.enabled', true);
        $this->assertNotEmpty($created->json('data.secret'));
        $this->assertStringStartsWith('whsec_', $created->json('data.secret'));
    }

    public function test_rotate_secret_returns_new_secret(): void
    {
        [$user, $team] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;
        $event = WebhookEventType::subscribableValues()[0];
        config(['webhooks.allow_private_addresses' => true]);
        $id = $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'https://example.test/hook', 'events' => [$event],
        ])->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/webhooks/{$id}/rotate-secret")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['secret']]);
    }

    /** @return array{User, Team} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);

        return [$user, $team];
    }
}
