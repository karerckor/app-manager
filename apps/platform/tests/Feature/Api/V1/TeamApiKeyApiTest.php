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

    public function test_owner_creates_and_revokes_key_but_cannot_exceed_calling_token(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);
        // Calling token holds api-keys:write + apps:read only.
        $caller = $owner->createTeamApiKey($team, 'caller', [
            ApiKeyScope::ApiKeysWrite->value, ApiKeyScope::AppsRead->value,
        ])->plainTextToken;

        // Requesting apps:write exceeds the calling token → 422.
        $this->withToken($caller)->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'too-wide', 'scopes' => [ApiKeyScope::AppsWrite->value],
        ])->assertUnprocessable();

        // Within the caller's scopes → 201 with the token shown once.
        $created = $this->withToken($caller)->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'reader', 'scopes' => [ApiKeyScope::AppsRead->value],
        ])->assertCreated();
        $this->assertNotEmpty($created->json('data.token'));
        $id = $created->json('data.id');

        $this->withToken($caller)->deleteJson("/api/v1/{$team->slug}/api-keys/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_admin_cannot_create_key_owner_only(): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($admin, TeamRole::Admin);

        $this->withToken($admin->createTeamApiKey($team, 'k', [ApiKeyScope::ApiKeysWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/api-keys", [
                'name' => 'x', 'scopes' => [ApiKeyScope::AppsRead->value],
            ])
            ->assertForbidden();
    }
}
