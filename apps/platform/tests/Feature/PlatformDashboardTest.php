<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiTokenAbilities;
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

    public function test_dashboard_exposes_scoped_api_key_management_data(): void
    {
        [$user, $team] = $this->userWithTeam();
        $token = $user->createToken('Production CI', ApiTokenAbilities::build(), now()->addMonth())->accessToken;

        $this->actingAs($user)->get(route('accounts.apps.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tokenStoreUrl', route('api-tokens.store'))
                ->has('tokenAbilities', 6)
                ->has('tokens', 1)
                ->where('tokens.0.id', $token->id)
                ->where('tokens.0.name', 'Production CI')
                ->where('tokens.0.abilities', ApiTokenAbilities::build())
                ->where('tokens.0.destroy_url', route('api-tokens.destroy', $token))
                ->where('tokens.0.expires_at', fn ($value) => filled($value)));
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

    public function test_app_drilldown_exposes_latest_build_history(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'is_default' => true,
        ]);
        $build = $team->buildRecords()->create([
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'external_id' => 'dashboard-build-12345',
            'profile_type' => ProfileType::Production,
            'status' => BuildStatus::Completed,
            'commit_sha' => str_repeat('a', 40),
            'ci_provider' => 'github',
            'ci_run_id' => '12345',
            'artifact_url' => 'https://example.test/atlas.aab',
            'context_schema_version' => 1,
            'context_snapshot' => [
                'target' => [
                    'platform' => Platform::Android->value,
                    'identifier' => 'com.example.atlas',
                    'version' => '2.3.0',
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $build->logs()->create([
            'sequence' => 1,
            'stream' => 'stdout',
            'level' => 'info',
            'message' => 'Build completed',
            'logged_at' => now(),
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->has('builds', 1)
                ->where('builds.0.id', $build->id)
                ->where('builds.0.external_id', 'dashboard-build-12345')
                ->where('builds.0.status', BuildStatus::Completed->value)
                ->where('builds.0.target.identifier', 'com.example.atlas')
                ->where('builds.0.ci.run_id', '12345')
                ->where('builds.0.logs_count', 1)
                ->where('builds.0.cancel_url', null));

        $this->getJson(route('accounts.apps.builds.logs.index', [$team, $app, $build]))
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Build completed');
    }

    public function test_owner_can_cancel_a_pending_build_from_the_app_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
        ]);
        $build = $team->buildRecords()->create([
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'external_id' => 'cancel-from-web',
            'profile_type' => ProfileType::Production,
            'status' => BuildStatus::Pending,
            'context_schema_version' => 1,
            'context_snapshot' => ['target' => ['identifier' => 'com.example.atlas']],
        ]);

        $url = route('accounts.apps.builds.cancel', [$team, $app, $build]);
        $this->actingAs($user)->patch($url)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(BuildStatus::Cancelled, $build->fresh()->status);
        $this->assertNotNull($build->fresh()->finished_at);
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

    public function test_web_identifier_workflow_manages_ios_build_numbers_and_default_targets(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(route('accounts.apps.identifiers.store', [$team, $app]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.atlas',
            'version' => '2.3.0',
            'build_number' => '20300',
        ])->assertRedirect();

        $first = $app->appIdentifiers()->firstOrFail();
        $this->assertSame('20300', $first->build_number);
        $this->assertTrue($first->is_default);

        $this->actingAs($user)->post(route('accounts.apps.identifiers.store', [$team, $app]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.atlas.beta',
            'name' => 'Beta',
            'is_default' => false,
        ])->assertRedirect();

        $second = $app->appIdentifiers()->where('identifier', 'com.example.atlas.beta')->firstOrFail();
        $this->assertFalse($second->is_default);

        $this->actingAs($user)->patch(route('accounts.apps.identifiers.update', [$team, $app, $second]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.atlas.beta',
            'name' => 'Beta',
            'version' => '2.3.1',
            'build_number' => '20301',
            'is_default' => true,
        ])->assertRedirect();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifiers.1.build_number', '20301')
                ->where('identifiers.1.is_default', true));
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

    public function test_user_can_update_an_application(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Old name',
            'slug' => 'old-name',
            'description' => 'Old description',
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => 'New name',
            'description' => 'New description',
        ])->assertRedirect(route('accounts.apps.show', [$team, $app]))
            ->assertSessionHas('status', 'Application updated.');

        $this->assertDatabaseHas('mobile_apps', [
            'id' => $app->id,
            'name' => 'New name',
            'description' => 'New description',
            'slug' => 'old-name',
        ]);
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
        $team->addUser($user, 'owner');
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
