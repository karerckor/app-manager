<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiKeys\ScopeAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_may_assign_any_scope_but_not_beyond_calling_token(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $guard = new ScopeAuthorization;

        // Web session (no calling token): role bounds only — owner may assign owner-only scope.
        $guard->assertAssignable($owner, $team, [ApiKeyScope::ApiKeysWrite->value], null);
        $this->assertTrue(true);

        // Calling token narrower than request → rejected.
        $this->expectException(ValidationException::class);
        $guard->assertAssignable($owner, $team, [ApiKeyScope::AppsWrite->value], [ApiKeyScope::AppsRead->value]);
    }

    public function test_non_owner_cannot_assign_scope_beyond_role(): void
    {
        $contributor = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $guard = new ScopeAuthorization;

        $this->expectException(ValidationException::class);
        // Contributor lacks ManageIntegrations → cannot mint integrations:read.
        $guard->assertAssignable($contributor, $team, [ApiKeyScope::IntegrationsRead->value], null);
    }
}
