<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_personal_team(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->current_team_id);
        $this->assertDatabaseHas('teams', ['id' => $user->current_team_id, 'owner_id' => $user->id]);
        $this->assertDatabaseHas('team_user', ['team_id' => $user->current_team_id, 'user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_user_can_create_and_revoke_an_api_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api-tokens', ['name' => 'CI'])->assertSessionHas('token');
        $token = $user->tokens()->firstOrFail();
        $this->actingAs($user)->delete("/api-tokens/{$token->id}")->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_user_can_only_switch_to_a_team_they_belong_to(): void
    {
        $user = User::factory()->create();
        $foreignTeam = Team::create(['name' => 'Foreign', 'owner_id' => User::factory()->create()->id]);

        $this->actingAs($user)->put("/teams/{$foreignTeam->id}/switch")->assertForbidden();
    }

    public function test_user_can_start_two_factor_setup_and_view_the_qr_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/user/confirm-password', ['password' => 'password'])
            ->assertRedirect();
        $this->post('/user/two-factor-authentication')->assertRedirect();
        $this->get('/dashboard')->assertSuccessful();

        $this->assertNotNull($user->refresh()->two_factor_secret);
    }
}
