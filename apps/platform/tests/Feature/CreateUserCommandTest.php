<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_and_their_team(): void
    {
        $this->artisan('user:create', [
            'email' => 'ada@example.com',
            'password' => 'Sup3rSecret!',
            'team' => 'Analytical Engine',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $team = Team::query()->sole();

        $this->assertSame('ada', $user->name);
        $this->assertTrue(Hash::check('Sup3rSecret!', $user->password));
        $this->assertSame('Analytical Engine', $team->name);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('audits', [
            'auditable_type' => Team::class,
            'auditable_id' => $team->id,
            'event' => 'membership_added',
            'team_id' => $team->id,
        ]);
    }

    public function test_it_reports_invalid_user_data_without_creating_a_team(): void
    {
        $this->artisan('user:create', [
            'email' => 'not-an-email',
            'password' => 'short',
            'team' => 'Analytical Engine',
        ])
            ->expectsOutputToContain('valid email')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_it_rejects_an_existing_email_without_creating_another_team(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:create', [
            'email' => 'ada@example.com',
            'password' => 'Sup3rSecret!',
            'team' => 'Analytical Engine',
        ])
            ->expectsOutputToContain('already been taken')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_it_rejects_an_empty_team_name(): void
    {
        $this->artisan('user:create', [
            'email' => 'ada@example.com',
            'password' => 'Sup3rSecret!',
            'team' => '',
        ])
            ->expectsOutputToContain('team field is required')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teams', 0);
    }
}
