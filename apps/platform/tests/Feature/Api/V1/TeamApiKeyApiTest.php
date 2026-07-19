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
        $admin = User::factory()->create();
        $adminTeam = Team::factory()->create();
        $adminTeam->addUser($admin, TeamRole::Admin);

        $this->withToken($admin->createTeamApiKey($adminTeam, 'k', [ApiKeyScope::ApiKeysRead->value])->plainTextToken)
            ->getJson("/api/v1/{$adminTeam->slug}/api-keys")
            ->assertOk();

        $contributor = User::factory()->create();
        $contributorTeam = Team::factory()->create();
        $contributorTeam->addUser($contributor, TeamRole::Contributor);

        $this->withToken($contributor->createTeamApiKey($contributorTeam, 'k', [ApiKeyScope::ApiKeysRead->value])->plainTextToken)
            ->getJson("/api/v1/{$contributorTeam->slug}/api-keys")
            ->assertForbidden();
    }

    public function test_show_returns_key(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $user->update(['current_team_id' => $team->id]);
        $key = $user->createTeamApiKey($team, 'reader', [ApiKeyScope::ApiKeysRead->value]);

        $response = $this->withToken($key->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/api-keys/{$key->accessToken->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'reader');

        $response->assertJsonMissingPath('data.token');
    }

    public function test_show_other_team_key_returns_404(): void
    {
        $user = User::factory()->create();
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $teamA->addUser($user, TeamRole::Owner);
        $user->update(['current_team_id' => $teamA->id]);
        $reader = $user->createTeamApiKey($teamA, 'reader', [ApiKeyScope::ApiKeysRead->value])->plainTextToken;

        $otherOwner = User::factory()->create();
        $teamB = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $teamB->addUser($otherOwner, TeamRole::Owner);
        $teamBKey = $otherOwner->createTeamApiKey($teamB, 'other-team-key', [ApiKeyScope::ApiKeysRead->value])->accessToken;

        $this->withToken($reader)
            ->getJson("/api/v1/{$teamA->slug}/api-keys/{$teamBKey->id}")
            ->assertNotFound();
    }
}
