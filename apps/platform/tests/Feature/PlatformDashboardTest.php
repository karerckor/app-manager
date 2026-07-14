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
        MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas']);
        MobileApp::factory()->create(['name' => 'Foreign app']);

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('apps', 1)
                ->where('apps.0.name', 'Atlas')
                ->where('stats.apps', 1));
    }

    public function test_user_can_create_an_application_in_the_current_team(): void
    {
        [$user, $team] = $this->userWithTeam();

        $response = $this->actingAs($user)->post(route('accounts.apps.store', $team), [
            'name' => 'Atlas Mobile',
            'description' => 'Customer application',
        ]);

        $app = MobileApp::firstOrFail();
        $response->assertRedirect("/accounts/{$team->slug}/apps/{$app->slug}");
        $this->assertSame($team->id, $app->team_id);
        $this->assertSame('atlas-mobile', $app->slug);
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

        $this->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Show')
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
        Storage::fake('public');
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
        $this->assertSame('release.keystore', $credential->getFirstMedia('file')->file_name);

        $this->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.0.profile.value', ProfileType::Production->value)
                ->where('credentials.0.profile.artifact', '.aab'));

        $this->get(route('accounts.credentials.download', [$team, $credential]))
            ->assertSuccessful()
            ->assertDownload('release.keystore');
    }

    public function test_app_icon_is_exposed_on_the_dashboard_and_drilldown_as_an_inline_preview(): void
    {
        Storage::fake('public');
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->addMedia(UploadedFile::fake()->image('app-icon.png', 128, 128))
            ->toMediaCollection('app_icon');
        $previewUrl = route('accounts.apps.assets.preview', [$team, $app, 'app_icon']);

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.icon_url', $previewUrl));

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

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
