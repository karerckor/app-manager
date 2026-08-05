<?php

namespace Tests\Feature;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamIntegrationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roleMatrix')]
    public function test_only_owner_and_admin_can_manage_integrations_and_run_pipelines(
        TeamRole $role,
        bool $allowed,
    ): void {
        [$user, $team] = $this->member($role);

        $this->assertSame($allowed, $user->can('manageIntegrations', $team));
        $this->assertSame($allowed, $user->can('runPipelines', $team));
        $this->assertSame($allowed, $team->allows($user, TeamPermission::ManageIntegrations));
        $this->assertSame($allowed, $team->allows($user, TeamPermission::RunPipelines));
    }

    public function test_unknown_team_roles_fail_closed(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => 'member']);

        $this->assertFalse($user->can('view', $team));
        $this->assertFalse($user->can('manageIntegrations', $team));
        $this->assertFalse($user->can('runPipelines', $team));
    }

    public function test_integration_permissions_are_shared_with_inertia(): void
    {
        [$admin, $team] = $this->member(TeamRole::Admin);

        $this->actingAs($admin)
            ->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.team.id', $team->id)
                ->where('auth.team.role', TeamRole::Admin->value)
                ->where('auth.team.permissions.manage_integrations', true)
                ->where('auth.team.permissions.run_pipelines', true)
                ->where('auth.team.permissions.close_team', false));
    }

    public function test_integration_sections_have_distinct_pages(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $urls = [
            'ci_cd' => route('accounts.integrations.index', $team),
            'expo' => route('accounts.integrations.eas.index', $team),
            'webhooks' => route('accounts.integrations.webhooks.index', $team),
            'notifications' => route('accounts.integrations.notifications.index', $team),
            'sentry' => route('accounts.integrations.sentry.index', $team),
        ];

        foreach ([
            'accounts.integrations.index' => 'ci_cd',
            'accounts.integrations.eas.index' => 'expo',
            'accounts.integrations.webhooks.index' => 'webhooks',
            'accounts.integrations.notifications.index' => 'notifications',
            'accounts.integrations.sentry.index' => 'sentry',
        ] as $routeName => $activeTab) {
            $this->actingAs($owner)
                ->get(route($routeName, $team))
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Integrations/Index')
                    ->where('activeTab', $activeTab)
                    ->where('integrationUrls', $urls));
        }
    }

    public static function roleMatrix(): array
    {
        return [
            'owner' => [TeamRole::Owner, true],
            'admin' => [TeamRole::Admin, true],
            'contributor' => [TeamRole::Contributor, false],
            'viewer' => [TeamRole::Viewer, false],
        ];
    }

    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
