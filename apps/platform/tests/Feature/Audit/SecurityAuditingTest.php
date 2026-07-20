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

        $key = ApiKey::create([
            'team_id' => $team->id,
            'name' => 'CI',
            'token' => hash('sha256', 'plain-secret-token'),
            'abilities' => ['*'],
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);

        $audit = Audit::query()->where('auditable_id', $key->id)->firstOrFail();
        $this->assertStringNotContainsString('plain-secret-token', $audit->toJson());
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
