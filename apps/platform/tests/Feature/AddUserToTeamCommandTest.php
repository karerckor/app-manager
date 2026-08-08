<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AddUserToTeamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_in_the_requested_team(): void
    {
        $team = Team::factory()->create([
            'name' => 'Analytical Engine',
            'slug' => 'analytical-engine',
        ]);

        $this->artisan('user:add-to-team', [
            'email' => 'Ada@Example.com',
            'password' => 'Sup3rSecret!',
            'team' => 'analytical-engine',
            'role' => 'contributor',
        ])
            ->expectsOutputToContain('Created user ada@example.com')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertSame('ada', $user->name);
        $this->assertTrue(Hash::check('Sup3rSecret!', $user->password));
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'contributor',
        ]);
        $this->assertDatabaseHas('audits', [
            'auditable_type' => Team::class,
            'auditable_id' => $team->id,
            'event' => 'membership_added',
            'team_id' => $team->id,
            'new_values->member_id' => $user->id,
            'new_values->role' => 'contributor',
        ]);
    }

    public function test_it_fails_when_the_team_slug_does_not_exist(): void
    {
        $this->artisan('user:add-to-team', [
            'email' => 'ada@example.com',
            'password' => 'Sup3rSecret!',
            'team' => 'missing-team',
            'role' => 'viewer',
        ])
            ->expectsOutputToContain('Team with slug "missing-team" was not found.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('team_user', 0);
    }

    public function test_it_rejects_invalid_and_owner_roles(): void
    {
        $team = Team::factory()->create(['slug' => 'analytical-engine']);
        $initialUserCount = User::query()->count();

        foreach (['member', 'owner'] as $role) {
            $this->artisan('user:add-to-team', [
                'email' => $role.'@example.com',
                'password' => 'Sup3rSecret!',
                'team' => $team->slug,
                'role' => $role,
            ])
                ->expectsOutputToContain('selected role is invalid')
                ->assertFailed();
        }

        $this->assertDatabaseCount('users', $initialUserCount);
        $this->assertDatabaseCount('team_user', 0);
    }

    public function test_it_rejects_invalid_user_data_without_adding_a_member(): void
    {
        $team = Team::factory()->create(['slug' => 'analytical-engine']);
        $initialUserCount = User::query()->count();

        $this->artisan('user:add-to-team', [
            'email' => 'not-an-email',
            'password' => 'short',
            'team' => $team->slug,
            'role' => 'admin',
        ])
            ->expectsOutputToContain('valid email')
            ->assertFailed();

        $this->assertDatabaseCount('users', $initialUserCount);
        $this->assertDatabaseCount('team_user', 0);
        $this->assertDatabaseCount('audits', 0);
    }
}
