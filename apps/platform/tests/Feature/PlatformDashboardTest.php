<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_only_apps_from_the_current_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Atlas',
            'tags' => [['production', '#10b981']],
        ]);
        MobileApp::factory()->create(['name' => 'Foreign app']);

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('apps.data', 1)
                ->where('apps.data.0.name', 'Atlas')
                ->where('apps.data.0.tags', [['production', '#10b981']])
                ->where('stats.apps', 1));
    }

    public function test_dashboard_paginates_apps_on_the_server(): void
    {
        [$user, $team] = $this->userWithTeam();
        MobileApp::factory()->count(12)->create([
            'team_id' => $team->id,
            'updated_at' => now(),
        ]);
        $oldestApp = MobileApp::factory()->create([
            'team_id' => $team->id,
            'updated_at' => now()->subDay(),
        ]);
        $oldestApp->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.oldest',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 12)
                ->where('apps.current_page', 1)
                ->where('apps.last_page', 2)
                ->where('apps.per_page', 12)
                ->where('apps.total', 13)
                ->where('stats.apps', 13)
                ->where('stats.identifiers', 1));

        $this->get(route('accounts.apps.index', ['team' => $team, 'page' => 2]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 1)
                ->where('apps.current_page', 2)
                ->where('apps.total', 13));
    }

    public function test_dashboard_searches_apps_on_the_server(): void
    {
        [$user, $team] = $this->userWithTeam();
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Needle by name',
            'updated_at' => now()->subMinute(),
        ]);
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Slug match',
            'slug' => 'needle-by-slug',
            'updated_at' => now()->subMinutes(2),
        ]);
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Description match',
            'description' => 'Contains the NEEDLE here.',
            'updated_at' => now()->subMinutes(3),
        ]);
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Tag match',
            'tags' => [['Needle tag', '#6366f1']],
            'updated_at' => now()->subMinutes(4),
        ]);
        MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Unrelated',
        ]);
        MobileApp::factory()->create([
            'name' => 'Needle in another workspace',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.index', [
            'team' => $team,
            'search' => ' needle ',
        ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 4)
                ->where('apps.total', 4)
                ->where('apps.data.0.name', 'Needle by name')
                ->where('apps.data.1.name', 'Slug match')
                ->where('apps.data.2.name', 'Description match')
                ->where('apps.data.3.name', 'Tag match')
                ->where('apps.data.3.tags', [['Needle tag', '#6366f1']])
                ->where('filters.search', 'needle')
                ->where('stats.apps', 5));
    }

    public function test_user_can_create_an_application_in_the_current_team(): void
    {
        [$user, $team] = $this->userWithTeam();

        $response = $this->actingAs($user)->post(route('accounts.apps.store', $team), [
            'name' => 'Atlas Mobile',
            'description' => 'Customer application',
            'metadata' => [
                'owner' => 'mobile-team',
                'release' => ['channel' => 'production'],
            ],
        ]);

        $app = MobileApp::firstOrFail();
        $response->assertRedirect("/accounts/{$team->slug}/apps/{$app->slug}");
        $this->assertSame($team->id, $app->team_id);
        $this->assertSame('atlas-mobile', $app->slug);
        $this->assertSame([
            'owner' => 'mobile-team',
            'release' => ['channel' => 'production'],
        ], $app->metadata);
    }

    public function test_user_can_add_an_identifier_and_view_the_app_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(route('accounts.apps.identifiers.store', [$team, $app]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
            'version' => '2.3.0',
        ])->assertRedirect();
        $identifier = $app->appIdentifiers()->firstOrFail();

        $this->get(route('accounts.apps.identifiers.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Index')
                ->where('app.id', $app->id)
                ->has('identifiers', 1)
                ->where('identifiers.0.identifier', 'com.example.atlas')
                ->where('identifiers.0.version', '2.3.0')
                ->where('identifiers.0.version_code', null)
                ->where('identifiers.0.update_url', route('accounts.apps.identifiers.update', [$team, $app, $identifier])));
    }

    public function test_user_can_update_an_existing_app_identifier(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas Android',
            'version' => '2.3.0',
            'version_code' => 20300,
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.identifiers.update', [$team, $app, $identifier]), [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.atlas.production',
            'name' => 'Atlas Production',
            'version' => '2.4.0',
            'version_code' => 20400,
        ])->assertRedirect()
            ->assertSessionHas('status', 'App identifier updated.');

        $this->assertDatabaseHas('app_identifiers', [
            'id' => $identifier->id,
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.atlas.production',
            'name' => 'Atlas Production',
            'version' => '2.4.0',
            'version_code' => 20400,
        ]);
    }

    public function test_identifier_cannot_be_updated_through_a_different_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.identifiers.update', [$team, $otherApp, $identifier]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.changed',
        ])->assertNotFound();

        $this->assertSame('com.example.atlas', $identifier->fresh()->identifier);
    }

    public function test_user_can_create_a_credential_upload_and_download_its_file(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas Android',
        ]);

        $this->actingAs($user)->post(route('accounts.apps.credentials.store', [$team, $app]), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Production keystore',
            'profile_type' => ProfileType::Production->value,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('release.keystore', 'keystore-binary'),
        ])->assertRedirect();

        $credential = Credential::firstOrFail();
        $profile = $identifier->profiles()->where('type', ProfileType::Production)->firstOrFail();
        $this->assertFalse($identifier->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($profile->credentials()->whereKey($credential->id)->exists());
        $media = $credential->getFirstMedia('file');
        $this->assertSame('release.keystore', $media->file_name);
        $this->assertSame(config('media-library.disk_name'), $media->disk);
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());

        $this->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Show')
                ->where('identifier.build_targets.0.type', ProfileType::Production->value)
                ->where('identifier.build_targets.0.artifact', '.aab')
                ->where('identifier.build_targets.0.credentials.0.profile.value', ProfileType::Production->value));

        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
        $this->get(route('accounts.credentials.download', [$team, $credential]))
            ->assertSuccessful()
            ->assertDownload('release.keystore');
    }

    public function test_identifier_attached_credentials_are_exposed_as_services(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas Android',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::FirebaseConfig,
            'name' => 'Firebase config',
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifier.build_targets', [])
                ->where('identifier.services.0.type', CredentialType::FirebaseConfig->value)
                ->where('identifier.services.0.name', 'Firebase config')
                ->where('identifier.services.0.app_identifier_id', $identifier->id)
                ->where('identifier.services.0.profile', null)
                ->has('identifier.services.0.update_url')
                ->has('identifier.services.0.tags_url'));
    }

    public function test_app_icon_is_exposed_on_the_dashboard_and_drilldown_as_an_inline_preview(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->addMedia(UploadedFile::fake()->image('app-icon.png', 128, 128))
            ->toMediaCollection('app_icon');
        $previewUrl = route('accounts.apps.assets.preview', [$team, $app, 'app_icon']);

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.data.0.icon_url', $previewUrl));

        $this->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.assets.app_icon.preview_url', $previewUrl));

        $this->get($previewUrl)
            ->assertSuccessful()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_user_cannot_open_an_app_from_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.apps.show', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
        $this->get(route('accounts.apps.assets.preview', [$foreignApp->team, $foreignApp, 'app_icon']))
            ->assertForbidden();
        $this->get("/accounts/{$team->slug}/apps/{$foreignApp->slug}")->assertNotFound();
    }

    public function test_user_can_update_an_application(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Old name',
            'slug' => 'old-name',
            'description' => 'Old description',
            'metadata' => ['owner' => 'old-team'],
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => 'New name',
            'description' => 'New description',
            'metadata' => [
                'owner' => 'mobile-team',
                'flags' => ['beta' => true],
            ],
        ])->assertRedirect(route('accounts.apps.show', [$team, $app]))
            ->assertSessionHas('status', 'Application updated.');

        $this->assertDatabaseHas('mobile_apps', [
            'id' => $app->id,
            'name' => 'New name',
            'description' => 'New description',
            'slug' => 'old-name',
        ]);
        $this->assertSame([
            'owner' => 'mobile-team',
            'flags' => ['beta' => true],
        ], $app->fresh()->metadata);
    }

    public function test_application_metadata_must_be_a_json_object_and_can_be_cleared(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'metadata' => ['owner' => 'mobile-team'],
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => $app->name,
            'metadata' => 'not-json',
        ])->assertSessionHasErrors('metadata');

        $this->assertSame(['owner' => 'mobile-team'], $app->fresh()->metadata);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => $app->name,
            'metadata' => null,
        ])->assertRedirect();

        $this->assertNull($app->fresh()->metadata);
    }

    public function test_application_metadata_is_exposed_on_the_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'metadata' => ['owner' => 'mobile-team'],
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.metadata.owner', 'mobile-team'));
    }

    public function test_update_requires_a_name(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Keep me']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => '',
            'description' => 'whatever',
        ])->assertSessionHasErrors('name');

        $this->assertSame('Keep me', $app->fresh()->name);
    }

    public function test_update_ignores_a_submitted_slug(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'stable-slug']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => 'Renamed',
            'slug' => 'hacked-slug',
        ])->assertRedirect();

        $this->assertSame('stable-slug', $app->fresh()->slug);
    }

    public function test_user_cannot_update_an_app_from_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create(['name' => 'Foreign']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$foreignApp->team, $foreignApp]), [
            'name' => 'Hijacked',
        ])->assertForbidden();

        $this->assertSame('Foreign', $foreignApp->fresh()->name);
    }

    public function test_update_url_is_exposed_on_the_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.urls.update', route('accounts.apps.update', [$team, $app])));
    }

    public function test_user_can_delete_an_application(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->delete(route('accounts.apps.destroy', [$team, $app]))
            ->assertRedirect(route('accounts.apps.index', $team))
            ->assertSessionHas('status', 'Application deleted.');

        $this->assertDatabaseMissing('mobile_apps', ['id' => $app->id]);
    }

    public function test_user_cannot_delete_an_app_from_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->delete(route('accounts.apps.destroy', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();

        $this->assertDatabaseHas('mobile_apps', ['id' => $foreignApp->id]);
    }

    public function test_update_and_destroy_404_when_app_belongs_to_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$foreignApp->slug}", ['name' => 'X'])
            ->assertNotFound();
        $this->delete("/accounts/{$team->slug}/apps/{$foreignApp->slug}")->assertNotFound();

        $this->assertDatabaseHas('mobile_apps', ['id' => $foreignApp->id]);
    }

    public function test_destroy_url_is_exposed_on_the_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.urls.destroy', route('accounts.apps.destroy', [$team, $app])));
    }

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
