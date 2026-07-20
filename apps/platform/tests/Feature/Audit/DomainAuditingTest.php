<?php

namespace Tests\Feature\Audit;

use App\Enums\CredentialType;
use App\Models\Audit;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainAuditingTest extends TestCase
{
    use RefreshDatabase;

    private function actAsWebUser(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        app(ActorContextManager::class)->set(ActorContext::forUser($user, 'web', null, '10.0.0.1', 'agent'));

        return [$user, $team];
    }

    public function test_creating_app_writes_audit_with_actor_and_source(): void
    {
        [$user, $team] = $this->actAsWebUser();

        $app = MobileApp::create(['team_id' => $team->id, 'name' => 'Acme', 'slug' => 'acme']);

        $audit = Audit::query()->where('auditable_id', $app->id)->firstOrFail();
        $this->assertSame('created', $audit->event);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('web', $audit->source);
        $this->assertSame($team->id, $audit->team_id);
    }

    public function test_credential_payload_is_never_audited(): void
    {
        [, $team] = $this->actAsWebUser();

        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::cases()[0],
            'name' => 'Signing',
            'payload' => ['secret' => 'super-secret-value'],
        ]);

        $credential->update(['name' => 'Renamed', 'payload' => ['secret' => 'rotated-value']]);

        $audits = Audit::query()->where('auditable_id', $credential->id)->get();
        $encoded = $audits->toJson();
        $this->assertStringNotContainsString('super-secret-value', $encoded);
        $this->assertStringNotContainsString('rotated-value', $encoded);
        $this->assertStringNotContainsString('payload', $encoded);
    }
}
