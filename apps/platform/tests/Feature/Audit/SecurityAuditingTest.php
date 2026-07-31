<?php

namespace Tests\Feature\Audit;

use App\Models\ApiKey;
use App\Models\Audit;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_token_is_excluded_from_audit(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        app(ActorContextManager::class)->set(ActorContext::forUser($owner, 'web'));

        $storedToken = hash('sha256', 'plain-secret-token');

        $key = ApiKey::create([
            'team_id' => $team->id,
            'name' => 'CI',
            'token' => $storedToken,
            'abilities' => ['*'],
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);

        $audit = Audit::query()->where('auditable_id', $key->id)->firstOrFail();
        $this->assertArrayNotHasKey('token', $audit->new_values);
        $this->assertStringNotContainsString($storedToken, $audit->toJson());
    }

    public function test_api_key_last_used_at_is_not_audited_but_domain_changes_are(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        app(ActorContextManager::class)->set(ActorContext::forUser($owner, 'web'));
        $key = ApiKey::factory()->create([
            'team_id' => $team->id,
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);
        Audit::query()
            ->where('auditable_type', ApiKey::class)
            ->where('auditable_id', $key->id)
            ->delete();

        $key->forceFill(['last_used_at' => now()])->save();
        $this->assertDatabaseMissing('audits', [
            'auditable_type' => ApiKey::class,
            'auditable_id' => $key->id,
        ]);

        $key->update(['name' => 'Renamed key']);
        $audit = Audit::query()
            ->where('auditable_type', ApiKey::class)
            ->where('auditable_id', $key->id)
            ->sole();
        $this->assertSame(['name' => 'Renamed key'], $audit->new_values);
    }

    public function test_cleanup_removes_only_api_key_audits_exclusively_about_last_used_at(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $key = ApiKey::factory()->create([
            'team_id' => $team->id,
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);
        $lastUsedOnly = Audit::create([
            'event' => 'updated',
            'auditable_type' => ApiKey::class,
            'auditable_id' => $key->id,
            'old_values' => ['last_used_at' => null],
            'new_values' => ['last_used_at' => now()->toDateTimeString()],
            'team_id' => $team->id,
            'source' => 'system',
        ]);
        $domainChange = Audit::create([
            'event' => 'updated',
            'auditable_type' => ApiKey::class,
            'auditable_id' => $key->id,
            'old_values' => ['name' => 'Old', 'last_used_at' => null],
            'new_values' => ['name' => 'New', 'last_used_at' => now()->toDateTimeString()],
            'team_id' => $team->id,
            'source' => 'web',
        ]);
        $emptySystemUpdate = Audit::create([
            'event' => 'updated',
            'auditable_type' => ApiKey::class,
            'auditable_id' => $key->id,
            'old_values' => [],
            'new_values' => [],
            'team_id' => $team->id,
            'source' => 'system',
        ]);
        $emptyWebUpdate = Audit::create([
            'event' => 'updated',
            'auditable_type' => ApiKey::class,
            'auditable_id' => $key->id,
            'old_values' => [],
            'new_values' => [],
            'team_id' => $team->id,
            'source' => 'web',
            'user_type' => User::class,
            'user_id' => $owner->id,
        ]);

        $migration = require database_path('migrations/2026_07_22_191000_remove_api_key_last_used_audits.php');
        $migration->up();
        $emptyAuditMigration = require database_path('migrations/2026_07_23_000000_remove_empty_system_api_key_update_audits.php');
        $emptyAuditMigration->up();

        $this->assertDatabaseMissing('audits', ['id' => $lastUsedOnly->id]);
        $this->assertDatabaseHas('audits', ['id' => $domainChange->id]);
        $this->assertDatabaseMissing('audits', ['id' => $emptySystemUpdate->id]);
        $this->assertDatabaseHas('audits', ['id' => $emptyWebUpdate->id]);
    }

    public function test_membership_change_records_audit(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $member = User::factory()->create();
        app(ActorContextManager::class)->set(ActorContext::forUser($owner, 'web'));

        $team->recordMembershipAudit($member, 'membership_added', 'member');

        $audit = Audit::query()
            ->where('auditable_type', Team::class)
            ->where('auditable_id', $team->id)
            ->where('event', 'membership_added')
            ->firstOrFail();

        $this->assertSame($member->id, $audit->new_values['member_id']);
        $this->assertSame('member', $audit->new_values['role']);
        $this->assertSame($owner->id, $audit->user_id);
    }
}
