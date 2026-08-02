<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Audit;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_lists_only_team_credentials_with_attachments_and_expiration_health(): void
    {
        [$owner, $team] = $this->team();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas Mobile']);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
        ]);
        $expired = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Production APNs',
            'type' => CredentialType::ApnsKey,
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            'expires_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        $identifier->credentials()->attach($expired);

        $expiring = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Distribution certificate',
            'type' => CredentialType::IosCertificate,
            'payload' => ['password' => 'never-expose-this'],
            'expires_at' => now()->addDays(12),
            'updated_at' => now()->subMinute(),
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $profile->credentials()->attach($expiring);

        Credential::factory()->create([
            'name' => 'Foreign credential',
            'type' => CredentialType::ApnsKey,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Credentials/Index')
                ->where('canManage', true)
                ->where('stats.total', 2)
                ->where('stats.expired', 1)
                ->where('stats.expiring', 1)
                ->where('stats.missing_files', 2)
                ->has('credentials.data', 2)
                ->where('credentials.data.0.name', 'Production APNs')
                ->where('credentials.data.0.expiration.status', 'expired')
                ->where('credentials.data.0.attachments.0.app_name', 'Atlas Mobile')
                ->where('credentials.data.0.attachments.0.identifier', 'com.example.atlas')
                ->where('credentials.data.1.name', 'Distribution certificate')
                ->where('credentials.data.1.expiration.status', 'expiring')
                ->where('credentials.data.1.attachments.0.profile.value', ProfileType::AppStore->value)
                ->where('credentials.data.1.payload.password', '')
                ->where('credentials.data.1.configured_fields', ['password'])
                ->where('credentials.data.1.password_fields_count', 1)
                ->where('credentials.data.1.reveal_url', route('accounts.credentials.reveal', [$team, $expiring]))
                ->missing('credentials.data.1.payload.never-expose-this')
                ->has('apps', 1)
                ->where('apps.0.identifiers.0.identifier', 'com.example.atlas'));
    }

    public function test_manager_can_reveal_certificate_and_keystore_passwords_with_their_account_password(): void
    {
        [$owner, $team] = $this->team();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Android release keystore',
            'type' => CredentialType::AndroidKeystore,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.data.0.password_fields_count', 2)
                ->where('credentials.data.0.payload.store_password', '')
                ->where('credentials.data.0.payload.key_alias', 'release')
                ->where('credentials.data.0.payload.key_password', ''));

        $this->postJson(
            route('accounts.credentials.reveal', [$team, $credential]),
            ['password' => 'incorrect'],
        )
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonValidationErrors('password');

        $this->postJson(
            route('accounts.credentials.reveal', [$team, $credential]),
            ['password' => 'password'],
        )
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertExactJson([
                'secrets' => [
                    [
                        'name' => 'store_password',
                        'label' => 'Store password',
                        'value' => 'store-secret',
                    ],
                    [
                        'name' => 'key_password',
                        'label' => 'Key password',
                        'value' => 'key-secret',
                    ],
                ],
            ]);

        $certificate = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'iOS distribution certificate',
            'type' => CredentialType::IosCertificate,
            'payload' => ['password' => 'certificate-secret'],
        ]);

        $this->postJson(
            route('accounts.credentials.reveal', [$team, $certificate]),
            ['password' => 'password'],
        )
            ->assertOk()
            ->assertExactJson([
                'secrets' => [[
                    'name' => 'password',
                    'label' => 'Certificate password',
                    'value' => 'certificate-secret',
                ]],
            ]);

        $this->assertGreaterThan(
            now()->subMinute()->timestamp,
            session('auth.password_confirmed_at'),
        );
        $this->assertSame(2, Audit::query()->where('event', 'credential_revealed')->count());
        $this->assertTrue(Audit::query()
            ->where('event', 'credential_revealed')
            ->where('user_id', $owner->id)
            ->where('source', 'web')
            ->exists());
    }

    public function test_vault_filters_credentials_by_search_type_status_and_application(): void
    {
        [$owner, $team] = $this->team();
        $atlas = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas Mobile']);
        $atlasIdentifier = $atlas->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Orbit']);
        $otherIdentifier = $otherApp->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.orbit',
        ]);

        $apns = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Push production',
            'type' => CredentialType::ApnsKey,
            'expires_at' => now()->subDay(),
        ]);
        $atlasIdentifier->credentials()->attach($apns);
        $serviceAccount = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Play publishing',
            'type' => CredentialType::GooglePlayServiceAccount,
            'expires_at' => null,
        ]);
        $otherIdentifier->credentials()->attach($serviceAccount);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [
                'team' => $team,
                'search' => 'Atlas',
                'type' => CredentialType::ApnsKey->value,
                'status' => 'expired',
                'app' => $atlas->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials.data', 1)
                ->where('credentials.data.0.id', $apns->id)
                ->where('filters.search', 'Atlas')
                ->where('filters.type', CredentialType::ApnsKey->value)
                ->where('filters.status', 'expired')
                ->where('filters.app', $atlas->id));
    }

    public function test_vault_ignores_an_empty_or_invalid_uuid_application_filter(): void
    {
        [$owner, $team] = $this->team();
        Credential::factory()->create(['team_id' => $team->id]);

        foreach (['', 'not-a-uuid'] as $appFilter) {
            $this->actingAs($owner)
                ->get(route('accounts.credentials.index', [
                    'team' => $team,
                    'app' => $appFilter,
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('credentials.data', 1)
                    ->where('filters.app', ''));
        }
    }

    public function test_vault_exposes_shared_usage_as_attachments_without_a_primary_application_binding(): void
    {
        [$owner, $team] = $this->team();
        $firstApp = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas']);
        $firstIdentifier = $firstApp->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $secondApp = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Orbit']);
        $secondIdentifier = $secondApp->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.orbit',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
            'name' => 'Shared APNs key',
        ]);
        $firstIdentifier->credentials()->attach($credential);
        $secondIdentifier->credentials()->attach($credential);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.data.0.id', $credential->id)
                ->has('credentials.data.0.attachments', 2)
                ->where('credentials.data.0.attachments.0.app_name', 'Atlas')
                ->where('credentials.data.0.attachments.1.app_name', 'Orbit')
                ->missing('credentials.data.0.app_id')
                ->missing('credentials.data.0.app_identifier_id')
                ->missing('credentials.data.0.profile'));
    }

    public function test_team_viewer_has_read_only_vault_access_and_outsider_is_forbidden(): void
    {
        [, $team] = $this->team();
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        $outsider = User::factory()->create();
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($viewer)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', false)
                ->where('credentials.data.0.update_url', null)
                ->where('credentials.data.0.delete_url', null)
                ->where('credentials.data.0.upload_url', null)
                ->where('credentials.data.0.tags_url', null));
        $this->actingAs($viewer)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('accounts.credentials.reveal', [$team, $credential]), ['password' => 'password'])
            ->assertForbidden();
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);

        $this->actingAs($outsider)
            ->get(route('accounts.credentials.index', $team))
            ->assertForbidden();
    }

    public function test_vault_filters_credentials_that_are_attached_to_no_application(): void
    {
        [$owner, $team] = $this->team();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas Mobile']);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);

        $viaIdentifier = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Attached to identifier',
            'type' => CredentialType::ApnsKey,
        ]);
        $identifier->credentials()->attach($viaIdentifier);

        $viaProfile = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Attached through profile',
            'type' => CredentialType::IosCertificate,
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $profile->credentials()->attach($viaProfile);

        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Orphaned keystore',
            'type' => CredentialType::AndroidKeystore,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'status' => 'unattached']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.unattached', 1)
                ->where('stats.never', 3)
                ->where('filters.status', 'unattached')
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Orphaned keystore'));
    }

    public function test_vault_filters_credentials_without_an_uploaded_secure_file(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();

        $withFile = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Distribution certificate',
            'type' => CredentialType::IosCertificate,
        ]);
        $withFile->addMediaFromString('certificate-bytes')
            ->usingFileName('dist.p12')
            ->toMediaCollection('file');

        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Keystore without a file',
            'type' => CredentialType::AndroidKeystore,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'status' => 'missing_file']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.missing_files', 1)
                ->where('filters.status', 'missing_file')
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Keystore without a file'));
    }

    public function test_vault_sorts_by_soonest_expiry_and_places_undated_credentials_last(): void
    {
        [$owner, $team] = $this->team();

        // updated_at order is deliberately the reverse of expiry order, so a
        // passing assertion cannot be explained by the default sort.
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'No expiry at all',
            'type' => CredentialType::GooglePlayServiceAccount,
            'expires_at' => null,
            'updated_at' => now(),
        ]);
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Expires in a year',
            'type' => CredentialType::ApnsKey,
            'expires_at' => now()->addDays(365),
            'updated_at' => now()->subMinute(),
        ]);
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Expires next week',
            'type' => CredentialType::IosCertificate,
            'expires_at' => now()->addDays(7),
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'sort' => 'expiry']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'expiry')
                ->where('credentials.data.0.name', 'Expires next week')
                ->where('credentials.data.1.name', 'Expires in a year')
                ->where('credentials.data.2.name', 'No expiry at all'));

        // Default: most recently updated first.
        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'updated')
                ->where('credentials.data.0.name', 'No expiry at all'));

        // Unknown values fall back instead of reaching orderByRaw.
        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [
                'team' => $team,
                'sort' => 'expires_at; drop table credentials',
                'status' => 'not-a-status',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'updated')
                ->where('filters.status', '')
                ->where('credentials.data.0.name', 'No expiry at all'));
    }

    private function team(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        return [$owner->fresh(), $team];
    }
}
