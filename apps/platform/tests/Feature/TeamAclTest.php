<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamAclTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roleMatrix')]
    public function test_team_role_permission_matrix(
        TeamRole $role,
        bool $manageCredentials,
        bool $manageApplications,
        bool $manageTeam,
        bool $closeTeam,
    ): void {
        [$user, $team] = $this->member($role);

        $this->assertTrue($user->can('view', $team));
        $this->assertSame($manageCredentials, $user->can('manageCredentials', $team));
        $this->assertSame($manageApplications, $user->can('manageApplications', $team));
        $this->assertSame($manageTeam, $user->can('manageTeam', $team));
        $this->assertSame($closeTeam, $user->can('delete', $team));
    }

    public function test_contributor_can_manage_credentials_but_cannot_change_applications(): void
    {
        [$user, $team] = $this->member(TeamRole::Contributor);
        [$app, $credential] = $this->appWithCredential($team);
        $this->authenticate($user);

        $credentialsUrl = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";

        $this->patchJson("{$credentialsUrl}/{$credential->id}", [
            'name' => 'Rotated certificate',
        ])->assertOk()->assertJsonPath('data.name', 'Rotated certificate');

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}", [
            'name' => 'Changed application',
        ])->assertForbidden();

        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers", [])
            ->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_manage_applications_or_credentials(): void
    {
        [$user, $team] = $this->member(TeamRole::Viewer);
        [$app, $credential] = $this->appWithCredential($team);
        $this->authenticate($user);

        $this->getJson("/api/v1/{$team->slug}/apps")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $credentialsUrl = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";
        $this->getJson($credentialsUrl)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("{$credentialsUrl}/{$credential->id}", ['name' => 'Nope'])->assertForbidden();
        $this->deleteJson("/api/v1/{$team->slug}/apps/{$app->slug}")->assertForbidden();
    }

    public function test_admin_can_manage_team_data_but_cannot_close_the_team(): void
    {
        [$user, $team] = $this->member(TeamRole::Admin);
        [$app, $credential] = $this->appWithCredential($team);
        $this->authenticate($user);

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}", [
            'name' => 'Admin managed app',
        ])->assertOk();

        $this->deleteJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}")
            ->assertNoContent();

        $this->actingAs($user)
            ->delete(route('accounts.destroy', $team))
            ->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_owner_can_close_the_team_and_is_switched_to_another_team(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $nextTeamOwner = User::factory()->create();
        $nextTeam = Team::factory()->create(['owner_id' => $nextTeamOwner->id]);
        $nextTeam->addUser($owner, TeamRole::Viewer);
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner)
            ->delete(route('accounts.destroy', $team))
            ->assertRedirect(route('accounts.apps.index', $nextTeam));

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertSame($nextTeam->id, $owner->refresh()->current_team_id);
    }

    public function test_owner_can_close_their_last_team_and_create_another_from_the_empty_state(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner)
            ->delete(route('accounts.destroy', $team))
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams/Empty')
                ->where('createTeamUrl', route('accounts.store')));
    }

    public function test_role_and_permissions_are_shared_with_inertia(): void
    {
        [$contributor, $team] = $this->member(TeamRole::Contributor);

        $this->actingAs($contributor)
            ->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('auth.team.role', TeamRole::Contributor->value)
                ->where('auth.team.permissions.view', true)
                ->where('auth.team.permissions.manage_credentials', true)
                ->where('auth.team.permissions.manage_applications', false)
                ->where('auth.team.permissions.manage_team', false)
                ->where('auth.team.permissions.close_team', false));
    }

    public function test_unknown_roles_fail_closed(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => 'member']);

        $this->assertFalse($user->can('view', $team));
    }

    public static function roleMatrix(): array
    {
        return [
            'owner' => [TeamRole::Owner, true, true, true, true],
            'admin' => [TeamRole::Admin, true, true, true, false],
            'contributor' => [TeamRole::Contributor, true, false, false, false],
            'viewer' => [TeamRole::Viewer, false, false, false, false],
        ];
    }

    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }

    private function appWithCredential(Team $team): array
    {
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $credential = Credential::factory()->create(['team_id' => $team->id]);
        $identifier->credentials()->attach($credential);

        return [$app, $credential];
    }

    private function authenticate(User $user): void
    {
        $this->withToken($user->createToken('ACL test')->plainTextToken);
    }
}
