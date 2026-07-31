<?php

namespace Tests\Feature;

use App\Enums\ApiKeyScope;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_and_revoke_a_team_api_key(): void
    {
        [$owner, $team] = $this->ownedTeam();

        $response = $this->actingAs($owner)->post(route('accounts.api-keys.store', $team), [
            'name' => 'GitHub Actions',
            'scopes' => [
                ApiKeyScope::BuildsWrite->value,
                ApiKeyScope::LogsWrite->value,
            ],
        ])->assertRedirect()
            ->assertSessionHas('token');

        $plainTextToken = $response->getSession()->get('token');
        $apiKey = $team->apiKeys()->firstOrFail();

        $this->assertStringStartsWith($apiKey->id.'|', $plainTextToken);
        $this->assertSame($owner->id, $apiKey->tokenable_id);
        $this->assertSame($team->id, $apiKey->team_id);
        $this->assertSame([
            ApiKeyScope::BuildsWrite->value,
            ApiKeyScope::LogsWrite->value,
        ], $apiKey->abilities);

        $this->get(route('accounts.api-keys.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiKeys/Index')
                ->has('apiKeys', 1)
                ->where('apiKeys.0.name', 'GitHub Actions')
                ->where('apiKeys.0.created_by.id', $owner->id)
                ->has('scopeGroups', 13));

        $this->delete(route('accounts.api-keys.destroy', [$team, $apiKey]))
            ->assertRedirect()
            ->assertSessionHas('status', 'API key revoked.');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $apiKey->id]);
    }

    public function test_api_key_scopes_are_required_and_must_be_known(): void
    {
        [$owner, $team] = $this->ownedTeam();

        $this->actingAs($owner)
            ->post(route('accounts.api-keys.store', $team), [
                'name' => 'No access',
                'scopes' => [],
            ])
            ->assertSessionHasErrors('scopes');

        $this->post(route('accounts.api-keys.store', $team), [
            'name' => 'Unknown access',
            'scopes' => ['root:everything'],
        ])->assertSessionHasErrors('scopes.0');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_owner_cannot_manage_team_api_keys(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'member']);
        $apiKey = $owner->createTeamApiKey($team, 'Owner key', [ApiKeyScope::AppsRead->value])->accessToken;

        $this->actingAs($member)
            ->get(route('accounts.api-keys.index', $team))
            ->assertForbidden();
        $this->post(route('accounts.api-keys.store', $team), [
            'name' => 'Member key',
            'scopes' => [ApiKeyScope::AppsRead->value],
        ])->assertForbidden();
        $this->delete(route('accounts.api-keys.destroy', [$team, $apiKey]))
            ->assertForbidden();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $apiKey->id]);
    }

    public function test_key_can_only_authenticate_against_its_team(): void
    {
        $user = User::factory()->create();
        $firstTeam = Team::factory()->create(['owner_id' => $user->id]);
        $secondTeam = Team::factory()->create(['owner_id' => $user->id]);
        $firstTeam->users()->attach($user, ['role' => 'owner']);
        $secondTeam->users()->attach($user, ['role' => 'owner']);
        MobileApp::factory()->create(['team_id' => $firstTeam->id]);
        MobileApp::factory()->create(['team_id' => $secondTeam->id]);

        $token = $user->createTeamApiKey(
            $firstTeam,
            'First team only',
            [ApiKeyScope::AppsRead->value],
        )->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$firstTeam->slug}/apps")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/{$secondTeam->slug}/apps")
            ->assertForbidden();
    }

    public function test_team_cascade_revokes_its_api_keys(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $apiKey = $owner->createTeamApiKey($team, 'CI', [ApiKeyScope::AppsRead->value])->accessToken;

        $team->delete();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $apiKey->id]);
    }

    /**
     * @return array{User, Team}
     */
    private function ownedTeam(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_team_id' => $team->id]);

        return [$owner, $team];
    }
}
