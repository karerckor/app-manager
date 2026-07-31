<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_team_page_but_only_managers_receive_pending_invitations(): void
    {
        [$owner, $team] = $this->team();
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        TeamInvitation::create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
            'email' => 'pending@example.com',
            'role' => 'contributor',
            'expires_at' => now()->addWeek(),
        ]);

        $this->actingAs($viewer)
            ->get(route('accounts.team.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Team/Index')
                ->where('canManage', false)
                ->has('members', 2)
                ->has('invitations', 0));

        $this->actingAs($owner)
            ->get(route('accounts.team.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Team/Index')
                ->where('canManage', true)
                ->where('checkCredentialUniquenessByDefault', true)
                ->has('invitations', 1));
    }

    public function test_admin_can_rename_team_and_invite_a_member(): void
    {
        Notification::fake();
        [$owner, $team] = $this->team();
        $admin = User::factory()->create();
        $team->addUser($admin, 'admin');

        $this->actingAs($admin)
            ->patch(route('accounts.team.update', $team), ['name' => 'Release Engineering'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->post(route('accounts.team.invitations.store', $team), [
                'email' => 'New.Member@Example.com',
                'role' => 'contributor',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Release Engineering']);
        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => 'new.member@example.com',
            'role' => 'contributor',
        ]);
        Notification::assertSentOnDemand(TeamInvitationNotification::class);
    }

    public function test_viewer_cannot_manage_team(): void
    {
        [, $team] = $this->team();
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');

        $this->actingAs($viewer)
            ->patch(route('accounts.team.update', $team), ['name' => 'Unauthorized'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('accounts.team.invitations.store', $team), ['email' => 'user@example.com', 'role' => 'viewer'])
            ->assertForbidden();
    }

    public function test_manager_can_change_the_default_credential_uniqueness_check(): void
    {
        [$owner, $team] = $this->team();

        $this->actingAs($owner)
            ->patch(route('accounts.team.update', $team), [
                'check_credential_uniqueness_by_default' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Credential uniqueness default updated.');

        $this->assertFalse($team->fresh()->check_credential_uniqueness_by_default);
    }

    public function test_manager_can_change_role_and_remove_non_owner_member(): void
    {
        [$owner, $team] = $this->team();
        $member = User::factory()->create(['current_team_id' => $team->id]);
        $team->addUser($member, 'viewer');

        $this->actingAs($owner)
            ->patch(route('accounts.team.members.update', [$team, $member]), ['role' => 'contributor'])
            ->assertRedirect();
        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'contributor']);

        $this->actingAs($owner)
            ->delete(route('accounts.team.members.destroy', [$team, $member]))
            ->assertRedirect();
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $member->id]);
        $this->assertNull($member->fresh()->current_team_id);
    }

    public function test_owner_membership_cannot_be_changed(): void
    {
        [$owner, $team] = $this->team();
        $admin = User::factory()->create();
        $team->addUser($admin, 'admin');

        $this->actingAs($admin)
            ->patch(route('accounts.team.members.update', [$team, $owner]), ['role' => 'viewer'])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->delete(route('accounts.team.members.destroy', [$team, $owner]))
            ->assertUnprocessable();
    }

    public function test_invitation_can_only_be_accepted_by_matching_user(): void
    {
        [$owner, $team] = $this->team();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $other = User::factory()->create();
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
            'email' => 'invitee@example.com',
            'role' => 'viewer',
            'expires_at' => now()->addWeek(),
        ]);

        $this->actingAs($other)
            ->post(route('team-invitations.accept', $invitation))
            ->assertForbidden();

        $this->actingAs($invitee)
            ->post(route('team-invitations.accept', $invitation))
            ->assertRedirect(route('accounts.apps.index', $team));

        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $invitee->id, 'role' => 'viewer']);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($team->id, $invitee->fresh()->current_team_id);
    }

    private function team(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        return [$owner, $team];
    }
}
