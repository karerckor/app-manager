<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\CredentialVerificationStatus;
use App\Enums\Platform;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_reports_a_successful_verification(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'verified',
            'expires_at' => '2027-03-01T00:00:00Z',
            'metadata' => ['version_id' => 'a1b2c3'],
        ])
            ->assertOk()
            ->assertJsonPath('data.verification.status', 'verified')
            ->assertJsonPath('data.metadata.version_id', 'a1b2c3')
            ->assertJsonPath('data.metadata_version', 2)
            ->assertJsonPath('data.metadata_source', 'pipeline');

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(CredentialVerificationStatus::Verified, $reference->verification_status);
        $this->assertNotNull($reference->verified_at);
        $this->assertNull($reference->verification_error);
        $this->assertSame(VaultMetadataSource::Pipeline, $reference->metadata_source);
        $this->assertSame('2027-03-01', $credential->fresh()->expires_at->toDateString());
    }

    public function test_a_failure_records_the_error_and_keeps_the_last_known_good_timestamp(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $verifiedAt = now()->subWeek();
        $credential->vaultReference->update([
            'verification_status' => CredentialVerificationStatus::Verified,
            'verified_at' => $verifiedAt,
        ]);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'AccessDeniedException: not authorized to perform secretsmanager:GetSecretValue',
        ])->assertOk();

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(CredentialVerificationStatus::Failed, $reference->verification_status);
        $this->assertStringContainsString('AccessDeniedException', $reference->verification_error);
        $this->assertSame(
            $verifiedAt->toDateTimeString(),
            $reference->verified_at->toDateTimeString(),
        );
    }

    public function test_the_failure_webhook_fires_on_the_transition_only(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'first failure',
        ])->assertOk();

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'second failure',
        ])->assertOk();

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_recovery_emits_no_event(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'failed', 'error' => 'boom'])
            ->assertOk();
        $this->postJson($this->url($team, $app, $credential), ['status' => 'verified'])
            ->assertOk();

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_an_error_is_required_with_a_failure_and_prohibited_with_a_success(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'failed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error');

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'verified',
            'error' => 'why is this here',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error');
    }

    public function test_an_expiry_cannot_accompany_a_failure(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'boom',
            'expires_at' => '2027-03-01T00:00:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_a_local_credential_cannot_be_verified(): void
    {
        [$user, $team, $app] = $this->externalCredential();
        $local = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);
        $app->appIdentifiers()->first()->credentials()->attach($local);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $local), ['status' => 'verified'])
            ->assertStatus(422);
    }

    public function test_the_verify_scope_is_required(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsRead]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'verified'])
            ->assertForbidden();
    }

    public function test_an_external_credential_missing_its_vault_reference_row_404s_instead_of_500ing(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $credential->vaultReference->delete();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'verified'])
            ->assertNotFound();
    }

    public function test_a_credential_attached_only_to_a_different_app_cannot_be_verified_through_this_apps_url(): void
    {
        [$user, $team, , $credential] = $this->externalCredential();
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $otherApp, $credential), ['status' => 'verified'])
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: Credential} */
    private function externalCredential(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);
        $credential = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);

        return [$user, $team, $app, $credential];
    }

    private function url(Team $team, MobileApp $app, Credential $credential): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification";
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }
}
