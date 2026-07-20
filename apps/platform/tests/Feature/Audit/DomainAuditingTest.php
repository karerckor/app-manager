<?php

namespace Tests\Feature\Audit;

use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
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

    public function test_webhook_endpoint_secret_is_never_audited(): void
    {
        [, $team] = $this->actAsWebUser();

        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http,
            'name' => 'Ops hook',
            'status' => IntegrationStatus::Active,
        ]);

        $plaintextSecret = 'whsec_super-secret-signing-value';

        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'secret' => $plaintextSecret,
            'secret_hint' => substr($plaintextSecret, -4),
        ]);

        $audits = Audit::query()->where('auditable_id', $endpoint->id)->get();

        $this->assertNotEmpty($audits);

        foreach ($audits as $audit) {
            // `secret_hint` legitimately contains the substring "secret", so a blanket
            // assertStringNotContainsString('secret', ...) on the whole JSON would be
            // fooled by it. Assert directly against the decoded new_values array instead.
            $this->assertArrayNotHasKey('secret', $audit->new_values);
            $this->assertArrayNotHasKey('secret', $audit->old_values);
        }

        $encoded = $audits->toJson();
        $this->assertStringNotContainsString($plaintextSecret, $encoded);
        $this->assertStringContainsString('secret_hint', $encoded);
    }

    public function test_build_record_parameters_are_never_audited(): void
    {
        $this->actAsWebUser();

        $sensitiveValue = 'wf-input-super-secret-token';

        $build = BuildRecord::factory()->create([
            'parameters' => ['token' => $sensitiveValue],
        ]);

        $audits = Audit::query()->where('auditable_id', $build->id)->get();

        $this->assertNotEmpty($audits);

        foreach ($audits as $audit) {
            $this->assertArrayNotHasKey('parameters', $audit->new_values);
            $this->assertArrayNotHasKey('parameters', $audit->old_values);
        }

        $encoded = $audits->toJson();
        $this->assertStringNotContainsString($sensitiveValue, $encoded);
        $this->assertStringNotContainsString('parameters', $encoded);
    }
}
