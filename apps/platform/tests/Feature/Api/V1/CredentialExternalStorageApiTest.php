<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialExternalStorageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_credential_is_created_and_exposed_with_a_vault_block(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
        ]);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";

        $created = $this->postJson($url, [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'storage_mode' => CredentialStorageMode::External->value,
            'vault' => [
                'provider' => VaultProvider::AwsSecretsManager->value,
                'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
                'metadata' => ['region' => 'eu-central-1'],
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.storage_mode', 'external')
            ->assertJsonPath('data.file', null)
            ->assertJsonPath('data.vault.provider', 'aws_secrets_manager')
            ->assertJsonPath('data.vault.resource', 'arn:aws:secretsmanager:eu-central-1:1:secret:apns')
            ->assertJsonPath('data.vault.metadata.region', 'eu-central-1')
            ->assertJsonPath('data.vault.metadata_version', 1)
            ->assertJsonPath('data.vault.metadata_source', 'user')
            ->assertJsonPath('data.vault.verification.status', 'unverified')
            ->assertJsonPath('data.vault.verification.verified_at', null);

        $id = $created->json('data.id');

        $this->getJson("{$url}/{$id}")
            ->assertOk()
            ->assertJsonPath('data.vault.resource', 'arn:aws:secretsmanager:eu-central-1:1:secret:apns');
    }

    public function test_local_credential_reports_a_null_vault_block(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
        ]);

        $this->post("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            'file' => UploadedFile::fake()->createWithContent('key.p8', $this->privateKey()),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.storage_mode', 'local')
            ->assertJsonPath('data.vault', null)
            ->assertJsonPath('data.file.name', 'key.p8');
    }

    public function test_external_creation_rejects_a_file(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->post("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'storage_mode' => CredentialStorageMode::External->value,
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'somewhere',
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            'file' => UploadedFile::fake()->createWithContent('key.p8', 'private-key'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_downloading_an_external_credential_file_is_not_found(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsReveal]);

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/file")
            ->assertNotFound();
    }

    public function test_uploading_a_file_to_an_external_credential_is_not_found(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/file";

        $this->post($url, [
            'file' => UploadedFile::fake()->createWithContent('key.p8', 'private-key'),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->deleteJson($url)->assertNotFound();
        $this->assertNull($credential->fresh()->getFirstMedia('file'));
    }

    public function test_partial_update_of_a_vault_block_with_missing_sub_keys_is_rejected(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}", [
            'vault' => [
                'resource' => 'somewhere-else',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vault.provider');
    }

    public function test_partial_update_with_only_a_payload_on_a_fileless_local_credential_succeeds(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}", [
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ])->assertOk();

        $this->assertSame(
            ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            $credential->fresh()->payload,
        );
    }

    public function test_partial_update_with_only_a_replacement_file_on_a_local_credential_succeeds(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $credential->addMediaFromString($this->privateKey())
            ->usingFileName('old.p8')
            ->toMediaCollection('file');
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->patch("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}", [
            'file' => UploadedFile::fake()->createWithContent('new.p8', $this->privateKey()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.file.name', 'new.p8');

        $this->assertSame('new.p8', $credential->fresh()->getFirstMedia('file')->file_name);
    }

    public function test_partial_update_of_an_external_credential_with_only_a_name_leaves_the_vault_reference_untouched(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $reference = $credential->vaultReference;
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}", [
            'name' => 'Renamed',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $fresh = $reference->fresh();
        $this->assertSame($reference->resource, $fresh->resource);
        $this->assertSame($reference->metadata_version, $fresh->metadata_version);
    }

    public function test_patching_a_file_onto_an_external_credential_is_rejected(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->patch("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}", [
            'file' => UploadedFile::fake()->createWithContent('key.p8', $this->privateKey()),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: AppIdentifier} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);

        return [$user, $team, $app, $identifier];
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
