<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_requires_authentication(): void
    {
        $this->get(route('profile.show'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_their_profile_settings(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('auth.user.name', $user->name)
                ->where('auth.user.email', $user->email)
                ->where('twoFactor.enabled', false)
                ->where('twoFactor.pending', false)
                ->where('twoFactor.passwordConfirmed', false)
                ->where('twoFactor.setupKey', null)
                ->where('ownedTeamsCount', 1));
    }

    public function test_pending_two_factor_setup_exposes_the_setup_key_only_after_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/user/confirm-password', ['password' => 'password'])
            ->assertRedirect();

        $this->post('/user/two-factor-authentication')
            ->assertRedirect();

        $setupKey = Fortify::currentEncrypter()->decrypt(
            $user->refresh()->two_factor_secret,
        );

        $this->get(route('profile.show'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.pending', true)
                ->where('twoFactor.passwordConfirmed', true)
                ->where('twoFactor.setupKey', $setupKey));

        $this->withSession(['auth.password_confirmed_at' => 0])
            ->get(route('profile.show'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.pending', true)
                ->where('twoFactor.passwordConfirmed', false)
                ->where('twoFactor.qrCode', null)
                ->where('twoFactor.setupKey', null));
    }

    public function test_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/profile-information', [
                'name' => 'Grace Hopper',
                'email' => 'grace@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'profile-information-updated');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ]);
    }

    public function test_recovery_codes_are_not_included_in_profile_props_and_require_a_password_to_reveal(): void
    {
        $user = User::factory()->create();
        $recoveryCodes = ['recovery-code-one', 'recovery-code-two'];

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.enabled', true)
                ->missing('twoFactor.recoveryCodes'));

        $this->postJson(route('profile.recovery-codes'), ['password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson(route('profile.recovery-codes'), ['password' => 'password'])
            ->assertSuccessful()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['codes' => $recoveryCodes]);
    }

    public function test_account_deletion_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'incorrect'])
            ->assertSessionHasErrors(['password'], null, 'deleteAccount');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_user_can_delete_their_account_and_owned_workspaces(): void
    {
        $user = User::factory()->create();
        $ownedTeam = Team::factory()->create(['owner_id' => $user->id]);
        $ownedTeam->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $ownedTeam->id]);

        $otherOwner = User::factory()->create();
        $sharedTeam = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $sharedTeam->users()->attach($user, ['role' => 'member']);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('teams', ['id' => $ownedTeam->id]);
        $this->assertDatabaseHas('teams', ['id' => $sharedTeam->id]);
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $sharedTeam->id,
            'user_id' => $user->id,
        ]);
    }
}
