<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_passes_and_viewer_is_forbidden_for_write_scope(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);

        $viewer = User::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);

        // Owner with apps:write may create an app.
        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Made by owner'])
            ->assertCreated();

        // Viewer holding the same write scope is blocked by role.
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($viewer->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Made by viewer'])
            ->assertForbidden();
    }
}
