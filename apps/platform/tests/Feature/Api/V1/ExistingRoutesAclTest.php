<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExistingRoutesAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_paginated(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        MobileApp::factory()->count(3)->create(['team_id' => $team->id]);

        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::AppsRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/apps?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_identifiers_index_is_paginated(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        AppIdentifier::factory()->count(3)->create(['mobile_app_id' => $app->id]);

        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::IdentifiersRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_credentials_index_is_paginated(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);

        Credential::factory()->count(3)->create(['team_id' => $team->id])
            ->each(fn (Credential $credential) => $credential->appIdentifiers()->attach($identifier));

        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::CredentialsRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_viewer_cannot_write_app_even_with_write_scope(): void
    {
        $viewer = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);

        $this->withToken($viewer->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'x'])
            ->assertForbidden();
    }
}
