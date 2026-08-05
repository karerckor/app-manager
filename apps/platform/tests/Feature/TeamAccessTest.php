<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_access_page_lists_members_roles_and_pending_invitations(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'viewer@example.com',
            'role' => TeamRole::Viewer,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.access', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams/Access')
                ->has('members', 2)
                ->where('members.0.id', $owner->id)
                ->where('members.0.role', TeamRole::Owner->value)
                ->where('members.1.role', TeamRole::Contributor->value)
                ->has('roles', 3)
                ->where('invitations.0.id', $invitation->id));
    }

    public function test_owner_or_admin_can_invite_a_member(): void
    {
        Notification::fake();
        [$owner, $team] = $this->ownedTeam();
        $admin = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $team->addUser($admin, TeamRole::Admin);

        $this->actingAs($admin)
            ->post(route('accounts.invitations.store', $team), [
                'email' => 'INVITEE@example.com',
                'role' => TeamRole::Contributor->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Invitation sent.');

        $invitation = TeamInvitation::firstOrFail();
        $this->assertSame('invitee@example.com', $invitation->email);
        $this->assertSame(TeamRole::Contributor, $invitation->role);
        $this->assertSame($admin->id, $invitation->invited_by);
        Notification::assertSentTo(
            $invitee,
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $notification) => $notification->invitation->is($invitation),
        );
    }

    public function test_invitation_can_be_accepted_only_by_the_matching_email(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => $invitee->email,
            'role' => TeamRole::Viewer,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($otherUser)->get($invitation->acceptUrl())->assertForbidden();

        $this->actingAs($invitee)
            ->get($invitation->acceptUrl())
            ->assertRedirect(route('accounts.access', $team));

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role' => TeamRole::Viewer->value,
        ]);
        $this->assertSame(TeamRole::Viewer, $invitee->teamRole($team));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertSame($team->id, $invitee->refresh()->current_team_id);
    }

    public function test_admin_can_change_roles_and_remove_non_owner_members(): void
    {
        [, $team] = $this->ownedTeam();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team->addUser($admin, TeamRole::Admin);
        $team->addUser($member, TeamRole::Contributor);

        $this->actingAs($admin)
            ->patch(route('accounts.members.update', [$team, $member]), [
                'role' => TeamRole::Viewer->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => TeamRole::Viewer->value,
        ]);
        $this->assertSame(TeamRole::Viewer, $member->teamRole($team));

        $this->delete(route('accounts.members.destroy', [$team, $member]))
            ->assertRedirect();
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
        $this->assertNull($member->teamRole($team));
    }

    public function test_owner_cannot_be_demoted_or_removed(): void
    {
        [$owner, $team] = $this->ownedTeam();

        $this->actingAs($owner)
            ->patch(route('accounts.members.update', [$team, $owner]), [
                'role' => TeamRole::Admin->value,
            ])
            ->assertUnprocessable();

        $this->delete(route('accounts.members.destroy', [$team, $owner]))
            ->assertUnprocessable();
    }

    public function test_contributor_and_viewer_cannot_manage_members_or_invitations(): void
    {
        [, $team] = $this->ownedTeam();
        $contributor = User::factory()->create();
        $viewer = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $team->addUser($viewer, TeamRole::Viewer);

        foreach ([$contributor, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route('accounts.access', $team))
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Teams/Access')
                    ->where('auth.team.permissions.manage_team', false)
                    ->has('invitations', 0));

            $this->post(route('accounts.invitations.store', $team), [
                'email' => 'new@example.com',
                'role' => TeamRole::Viewer->value,
            ])->assertForbidden();
        }
    }

    public function test_pending_invitation_can_be_revoked(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'viewer@example.com',
            'role' => TeamRole::Viewer,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner)
            ->delete(route('accounts.invitations.destroy', [$team, $invitation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Invitation revoked.');

        $this->assertModelMissing($invitation);
    }

    private function ownedTeam(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);

        return [$owner, $team];
    }
}
