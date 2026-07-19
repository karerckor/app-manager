<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamApiKeyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_keys_without_token(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $user->update(['current_team_id' => $team->id]);
        $reader = $user->createTeamApiKey($team, 'reader', [ApiKeyScope::ApiKeysRead->value])->plainTextToken;

        $response = $this->withToken($reader)
            ->getJson("/api/v1/{$team->slug}/api-keys")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'reader');

        $response->assertJsonMissingPath('data.0.token');
    }

    public function test_admin_can_read_but_contributor_cannot(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($user, TeamRole::Contributor);

        $this->withToken($user->createTeamApiKey($team, 'k', [ApiKeyScope::ApiKeysRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/api-keys")
            ->assertForbidden();
    }
}
