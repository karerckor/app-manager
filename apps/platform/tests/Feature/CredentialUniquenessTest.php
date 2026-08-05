<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Credentials\CredentialUniqueness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_check_reuses_matching_team_credential_without_storing_a_duplicate_file(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $firstIdentifier] = $this->application();
        $secondIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
        ]);
        $contents = $this->googleServicesJson();

        $this->actingAs($user)
            ->post($this->storeUrl($team, $app), [
                'app_identifier_id' => $firstIdentifier->id,
                'type' => CredentialType::GoogleServicesJson->value,
                'name' => 'Primary Firebase config',
                'check_uniqueness' => true,
                'file' => UploadedFile::fake()->createWithContent('google-services.json', $contents),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Credential added.');

        $credential = Credential::sole();
        $originalMedia = $credential->getFirstMedia('file');

        $this->actingAs($user)
            ->post($this->storeUrl($team, $app), [
                'app_identifier_id' => $secondIdentifier->id,
                'type' => CredentialType::GoogleServicesJson->value,
                'name' => 'A duplicate name that must not be saved',
                'check_uniqueness' => true,
                'file' => UploadedFile::fake()->createWithContent('firebase-copy.json', $contents),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Duplicate found. Existing credential reused from Credentials Vault.',
            );

        $this->assertDatabaseCount('credentials', 1);
        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseCount('credentialables', 2);
        $this->assertSame('Primary Firebase config', $credential->fresh()->name);
        $this->assertSame($originalMedia->id, $credential->fresh()->getFirstMedia('file')->id);
        $this->assertTrue($firstIdentifier->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($secondIdentifier->credentials()->whereKey($credential->id)->exists());
        Storage::disk(config('media-library.disk_name'))
            ->assertExists($originalMedia->getPathRelativeToRoot());
    }

    public function test_disabled_check_allows_matching_credentials_and_files_to_be_stored(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->application();
        $contents = $this->googleServicesJson();

        foreach (['First config', 'Second config'] as $name) {
            $this->actingAs($user)
                ->post($this->storeUrl($team, $app), [
                    'app_identifier_id' => $identifier->id,
                    'type' => CredentialType::GoogleServicesJson->value,
                    'name' => $name,
                    'check_uniqueness' => false,
                    'file' => UploadedFile::fake()->createWithContent('google-services.json', $contents),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('credentials', 2);
        $this->assertDatabaseCount('media', 2);
        $this->assertDatabaseCount('credentialables', 2);
        $this->assertSame(1, Credential::query()->distinct()->count('fingerprint'));
    }

    public function test_team_default_is_used_when_the_form_value_is_missing(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->application();
        $contents = $this->googleServicesJson();

        foreach (['First config', 'Second config'] as $name) {
            $this->actingAs($user)
                ->post($this->storeUrl($team, $app), [
                    'app_identifier_id' => $identifier->id,
                    'type' => CredentialType::GoogleServicesJson->value,
                    'name' => $name,
                    'file' => UploadedFile::fake()->createWithContent('google-services.json', $contents),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertTrue($team->fresh()->check_credential_uniqueness_by_default);
        $this->assertDatabaseCount('credentials', 1);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_disabled_team_default_allows_duplicates_when_the_form_value_is_missing(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->application();
        $team->update(['check_credential_uniqueness_by_default' => false]);
        $contents = $this->googleServicesJson();

        foreach (['First config', 'Second config'] as $name) {
            $this->actingAs($user)
                ->post($this->storeUrl($team, $app), [
                    'app_identifier_id' => $identifier->id,
                    'type' => CredentialType::GoogleServicesJson->value,
                    'name' => $name,
                    'file' => UploadedFile::fake()->createWithContent('google-services.json', $contents),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('credentials', 2);
        $this->assertDatabaseCount('media', 2);
    }

    public function test_check_fingerprints_existing_vault_credentials_lazily(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->application();
        $contents = $this->googleServicesJson();
        $existing = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GoogleServicesJson,
            'name' => 'Existing config',
            'fingerprint' => null,
        ]);
        $existing->addMediaFromString($contents)
            ->usingFileName('google-services.json')
            ->toMediaCollection('file');

        $this->actingAs($user)
            ->post($this->storeUrl($team, $app), [
                'app_identifier_id' => $identifier->id,
                'type' => CredentialType::GoogleServicesJson->value,
                'name' => 'Duplicate upload',
                'check_uniqueness' => true,
                'file' => UploadedFile::fake()->createWithContent('copy.json', $contents),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Duplicate found. Existing credential reused from Credentials Vault.',
            );

        $this->assertNotNull($existing->fresh()->fingerprint);
        $this->assertDatabaseCount('credentials', 1);
        $this->assertDatabaseCount('media', 1);
        $this->assertTrue($identifier->credentials()->whereKey($existing->id)->exists());
    }

    public function test_identifying_payload_fields_are_part_of_the_fingerprint(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $identifier] = $this->application();

        foreach (['release', 'upload'] as $alias) {
            $this->actingAs($user)
                ->post($this->storeUrl($team, $app), [
                    'app_identifier_id' => $identifier->id,
                    'type' => CredentialType::AndroidKeystore->value,
                    'profile_type' => ProfileType::Production->value,
                    'name' => ucfirst($alias).' key',
                    'payload' => [
                        'store_password' => 'store-secret',
                        'key_alias' => $alias,
                        'key_password' => 'key-secret',
                    ],
                    'check_uniqueness' => true,
                    'file' => UploadedFile::fake()->createWithContent('release.jks', 'same-keystore'),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('credentials', 2);
        $this->assertSame(2, Credential::query()->distinct()->count('fingerprint'));
    }

    public function test_both_creation_pages_receive_the_team_default(): void
    {
        [$user, $team, $app, $identifier] = $this->application();
        $team->update(['check_credential_uniqueness_by_default' => false]);

        $this->actingAs($user)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkUniquenessByDefault', false));

        $this->actingAs($user)
            ->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkUniquenessByDefault', false));
    }

    public function test_matching_vault_reference_is_found_within_the_team_and_type(): void
    {
        $team = Team::factory()->create();
        $existing = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:ios')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $match = app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            'arn:aws:secretsmanager:eu:1:secret:ios',
        );

        $this->assertNotNull($match);
        $this->assertSame($existing->id, $match->id);
    }

    public function test_vault_reference_does_not_match_across_teams_types_or_providers(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $resource = 'arn:aws:secretsmanager:eu:1:secret:ios';

        Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, $resource)
            ->create(['team_id' => $other->id, 'type' => CredentialType::ApnsKey]);
        Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, $resource)
            ->create(['team_id' => $team->id, 'type' => CredentialType::AppleApiKey]);
        Credential::factory()
            ->external(VaultProvider::HashicorpVault, $resource)
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->assertNull(app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            $resource,
        ));
    }

    public function test_local_credentials_are_never_returned_as_vault_matches(): void
    {
        $team = Team::factory()->create();
        Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $this->assertNull(app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            'arn:aws:secretsmanager:eu:1:secret:ios',
        ));
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
        ]);

        return [$user->fresh(), $team, $app, $identifier];
    }

    private function storeUrl(Team $team, MobileApp $app): string
    {
        return route('accounts.apps.credentials.store', [$team, $app]);
    }

    private function googleServicesJson(): string
    {
        return json_encode([
            'project_info' => [
                'project_id' => 'demo',
                'project_number' => '123',
            ],
            'client' => [[
                'client_info' => ['mobilesdk_app_id' => '1:123:android:abc'],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
