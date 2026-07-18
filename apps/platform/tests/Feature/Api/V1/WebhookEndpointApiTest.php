<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_endpoints_without_leaking_secret(): void
    {
        [$user, $team, $endpoint] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksRead->value])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/webhooks")
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://example.test/hook')
            ->assertJsonPath('data.0.secret_hint', substr($endpoint->secret, -4))
            ->assertJsonPath('data.0.enabled', true);

        $this->assertStringNotContainsString($endpoint->secret, $response->getContent());
        $response->assertJsonMissingPath('data.0.secret');
    }

    /** @return array{User, Team, WebhookEndpoint} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http, 'name' => 'Ops hook',
            'status' => IntegrationStatus::Active,
        ]);
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id,
            'url' => 'https://example.test/hook',
        ]);

        return [$user, $team, $endpoint];
    }
}
