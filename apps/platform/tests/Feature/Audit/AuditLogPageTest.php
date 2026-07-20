<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_owner_sees_team_scoped_audits(): void
    {
        [$user, $team] = $this->owner();
        // MobileApp is itself Auditable, so creating it via the factory would
        // record its own "created" audit and inflate the count below. Disable
        // auditing for this setup step; only the audits created explicitly
        // in this test should be counted.
        MobileApp::disableAuditing();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        MobileApp::enableAuditing();
        Audit::create([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => 'created',
            'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => ['name' => 'Acme'],
            'team_id' => $team->id, 'source' => 'web',
        ]);
        $other = Team::factory()->create();
        Audit::create([
            'event' => 'created', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $other->id, 'source' => 'web',
        ]);

        $this->actingAs($user)->get(route('accounts.audit.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLog/Index')
                ->has('audits.data', 1));
    }

    public function test_non_owner_member_is_forbidden(): void
    {
        [, $team] = $this->owner();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'member']);
        $member->update(['current_team_id' => $team->id]);

        $this->actingAs($member->fresh())->get(route('accounts.audit.index', $team))->assertForbidden();
    }

    public function test_non_member_is_forbidden(): void
    {
        [, $team] = $this->owner();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('accounts.audit.index', $team))->assertForbidden();
    }
}
