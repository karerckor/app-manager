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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_integrations_without_leaking_installation_id(): void
    {
        [$user, $team] = $this->ctx();
        Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
            'external_account_name' => 'octo',
        ]);

        $response = $this->withToken($this->key($user, $team, ApiKeyScope::IntegrationsRead))
            ->getJson("/api/v1/{$team->slug}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'GitHub octo')
            ->assertJsonPath('data.0.external_account_name', 'octo')
            ->assertJsonPath('meta.total', 1);

        $this->assertStringNotContainsString('"42"', $response->getContent());
    }

    public function test_admin_role_required(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);

        $this->withToken($this->key($contributor, $team, ApiKeyScope::IntegrationsRead))
            ->getJson("/api/v1/{$team->slug}/integrations")
            ->assertForbidden();
    }

    /** @return array{User, Team} */
    private function ctx(TeamRole $role = TeamRole::Owner): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }

    private function key(User $user, Team $team, ApiKeyScope $scope): string
    {
        return $user->createTeamApiKey($team, $scope->value, [$scope->value])->plainTextToken;
    }
}
