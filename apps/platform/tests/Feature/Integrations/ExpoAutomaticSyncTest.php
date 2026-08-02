<?php

namespace Tests\Feature\Integrations;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\TeamRole;
use App\Integrations\Expo\ExpoPushSynchronizer;
use App\Jobs\Integrations\PushExpoProject;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpoAutomaticSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.enabled', true);
        config()->set('integrations.expo.enabled', true);
        config()->set('integrations.expo.graphql_url', 'https://expo.test/graphql');
        config()->set('integrations.expo.web_url', 'https://expo.test');
    }

    public function test_automatic_sync_is_opt_in_and_scoped_to_one_application(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $project = $this->project($integration, $app, 'project-1');
        $otherProject = $this->project($integration, $otherApp, 'project-2');

        $this->assertFalse($project->refresh()->auto_sync_enabled);
        $this->actingAs($owner)
            ->from(route('accounts.apps.expo.index', [$team, $app]))
            ->patch(route('accounts.apps.expo.automatic-sync.update', [$team, $app]), [
                'enabled' => true,
            ])
            ->assertRedirect(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSessionHas(
                'status',
                'Automatic Expo sync enabled. The first push was queued.',
            );

        $this->assertTrue($project->refresh()->auto_sync_enabled);
        $this->assertSame('queued', $project->auto_sync_status);
        $this->assertFalse($otherProject->refresh()->auto_sync_enabled);
        Queue::assertPushed(
            PushExpoProject::class,
            fn (PushExpoProject $job) => $job->expoProjectId === $project->id,
        );

        $this->actingAs($owner)
            ->patch(route('accounts.apps.expo.automatic-sync.update', [$team, $app]), [
                'enabled' => false,
            ])
            ->assertRedirect();
        $this->assertFalse($project->refresh()->auto_sync_enabled);
        $this->assertSame('idle', $project->auto_sync_status);
    }

    public function test_local_name_icon_identifier_and_credential_changes_queue_a_push(): void
    {
        Queue::fake();
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $project = $this->project($integration, $app);
        $project->update(['auto_sync_enabled' => true]);

        $this->actingAs($owner)
            ->patch(route('accounts.apps.update', [$team, $app]), [
                'name' => 'Renamed locally',
                'description' => $app->description,
            ])
            ->assertRedirect(route('accounts.apps.show', [$team, $app]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed locally', $app->refresh()->name);
        $this->assertTrue(
            Queue::pushed(PushExpoProject::class)->isNotEmpty(),
            'A name change did not queue an Expo push.',
        );

        Queue::fake();
        $this->actingAs($owner)
            ->post(route('accounts.apps.assets.upload', [$team, $app, 'app_icon']), [
                'file' => UploadedFile::fake()->image('icon.png', 1024, 1024),
            ])
            ->assertRedirect();
        $this->assertTrue(
            Queue::pushed(PushExpoProject::class)->isNotEmpty(),
            'An icon upload did not queue an Expo push.',
        );

        Queue::fake();
        $this->actingAs($owner)
            ->post(route('accounts.apps.identifiers.store', [$team, $app]), [
                'platform' => Platform::Android->value,
                'identifier' => 'com.example.automatic',
                'name' => 'Android',
            ])
            ->assertRedirect();
        $this->assertTrue(
            Queue::pushed(PushExpoProject::class)->isNotEmpty(),
            'An identifier change did not queue an Expo push.',
        );

        $identifier = $app->appIdentifiers()->firstOrFail();
        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::FirebaseConfig,
            'name' => 'Firebase service account',
        ]);
        $identifier->credentials()->attach($credential);
        Queue::fake();
        $this->actingAs($owner)
            ->post(route('accounts.credentials.upload', [$team, $credential]), [
                'file' => UploadedFile::fake()->createWithContent(
                    'firebase.json',
                    json_encode([
                        'type' => 'service_account',
                        'project_id' => 'automatic',
                        'client_email' => 'firebase@example.test',
                        'private_key' => $this->privateKey(),
                    ], JSON_THROW_ON_ERROR),
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertTrue(
            Queue::pushed(PushExpoProject::class)->isNotEmpty(),
            'A credential upload did not queue an Expo push.',
        );
    }

    public function test_api_can_enable_sync_and_disable_it_after_the_connection_degrades(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $project = $this->project($integration, $app);
        $token = $owner->createTeamApiKey($team, 'Expo automatic sync', [
            ApiKeyScope::AppsWrite->value,
            ApiKeyScope::CredentialsWrite->value,
            ApiKeyScope::IntegrationsWrite->value,
        ])->plainTextToken;
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/expo/automatic-sync";

        $this->withToken($token)
            ->patchJson($url, ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.automatic_sync.enabled', true)
            ->assertJsonPath('data.automatic_sync.status', 'queued');
        Queue::assertPushed(
            PushExpoProject::class,
            fn (PushExpoProject $job) => $job->expoProjectId === $project->id,
        );

        $integration->update(['status' => IntegrationStatus::Degraded]);
        $this->withToken($token)
            ->patchJson($url, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.automatic_sync.enabled', false)
            ->assertJsonPath('data.automatic_sync.status', 'idle');
    }

    public function test_manual_pull_does_not_queue_an_automatic_push(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app)->update([
            'auto_sync_enabled' => true,
            'sync_metadata' => true,
        ]);
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => [
                    'id' => 'project-1',
                    'name' => 'Name from Expo',
                    'slug' => 'atlas',
                    'fullName' => '@acme/atlas',
                    'description' => 'Pulled explicitly',
                    'profileImageUrl' => null,
                    'updated' => now()->toIso8601String(),
                    'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                    'androidAppCredentials' => [],
                    'iosAppCredentials' => [],
                    'builds' => [],
                    'submissions' => [],
                    'buildProfiles' => [],
                ]]],
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.sync', [$team, $app]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo project synced.');

        $this->assertSame('Name from Expo', $app->refresh()->name);
        Queue::assertNotPushed(PushExpoProject::class);
    }

    public function test_push_job_updates_expo_and_reuses_its_snapshot_on_the_next_run(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Atlas Local',
        ]);
        $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Android',
        ]);
        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::FirebaseConfig,
            'name' => 'Firebase HTTP v1',
        ]);
        $credential->addMediaFromString(json_encode([
            'type' => 'service_account',
            'project_id' => 'atlas',
            'client_email' => 'firebase@example.test',
            'private_key' => 'test-only',
        ], JSON_THROW_ON_ERROR))
            ->usingFileName('firebase.json')
            ->toMediaCollection('file');
        $identifier->credentials()->attach($credential);
        $project = $this->project($integration, $app);
        $project->update(['auto_sync_enabled' => true]);

        $androidCredentialsExist = false;
        Http::fake(function (HttpRequest $request) use (&$androidCredentialsExist) {
            $query = (string) data_get($request->data(), 'query', '');

            if (str_contains($query, 'AppManagerCreateAndroidAppCredentials')) {
                $androidCredentialsExist = true;

                return Http::response([
                    'data' => ['androidAppCredentials' => ['createAndroidAppCredentials' => [
                        'id' => 'android-app-credentials-1',
                    ]]],
                ]);
            }

            return match (true) {
                $request->url() === 'https://uploads.expo.test/icon' => Http::response('', 200),
                str_contains($query, 'AppManagerSetExpoProjectInfo') => Http::response([
                    'data' => ['app' => ['setAppInfo' => [
                        'id' => 'project-1',
                        'name' => 'Atlas Local',
                        'slug' => 'atlas',
                        'profileImageUrl' => null,
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateExpoProjectIconUpload') => Http::response([
                    'data' => ['uploadSession' => ['createAppScopedUploadSession' => [
                        'url' => 'https://uploads.expo.test/icon',
                        'headers' => ['x-upload-token' => 'signed'],
                        'bucketKey' => 'project-icons/project-1.png',
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProjectSummary') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'name' => 'Atlas Local',
                        'slug' => 'atlas',
                        'fullName' => '@acme/atlas',
                        'profileImageUrl' => 'https://cdn.expo.test/project-1.png',
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'androidAppCredentials' => $androidCredentialsExist ? [[
                            'id' => 'android-app-credentials-1',
                            'applicationIdentifier' => 'com.example.atlas',
                        ]] : [],
                        'iosAppCredentials' => [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateFcmV1Credential') => Http::response([
                    'data' => ['androidAppCredentials' => ['createFcmV1Credential' => [
                        'id' => 'android-app-credentials-1',
                        'googleServiceAccountKeyForFcmV1' => [
                            'id' => 'fcm-key-1',
                            'clientEmail' => 'firebase@example.test',
                            'projectIdentifier' => 'atlas',
                        ],
                    ]]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected request.']]]),
            };
        });

        $job = new PushExpoProject($project->id);
        $job->handle(app(ExpoPushSynchronizer::class));

        $project->refresh();
        $this->assertSame('synced', $project->auto_sync_status);
        $this->assertNotNull($project->last_pushed_at);
        $this->assertSame(
            'android-app-credentials-1',
            data_get($project->auto_sync_snapshot, "identifiers.{$identifier->id}.remote_id"),
        );
        $this->assertSame(
            'fcm-key-1',
            data_get(
                $project->auto_sync_snapshot,
                "credentials.{$identifier->id}:{$credential->id}.remote_id",
            ),
        );
        $this->assertSame('https://cdn.expo.test/project-1.png', $project->icon_url);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerSetExpoProjectInfo',
        ) && data_get($request->data(), 'variables.appInfo.displayName') === 'Atlas Local');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://uploads.expo.test/icon'
            && $request->hasHeader('x-upload-token', 'signed'));

        $job->handle(app(ExpoPushSynchronizer::class));
        Http::assertSentCount(8);
    }

    public function test_initial_push_removes_the_remote_icon_when_app_manager_has_none(): void
    {
        [, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas']);
        $project = $this->project($integration, $app);
        $project->update([
            'auto_sync_enabled' => true,
            'icon_url' => 'https://cdn.expo.test/old-icon.png',
        ]);
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerSetExpoProjectInfo') => Http::response([
                    'data' => ['app' => ['setAppInfo' => ['id' => 'project-1', 'name' => 'Atlas']]],
                ]),
                str_contains($query, 'AppManagerRemoveExpoProjectIcon') => Http::response([
                    'data' => ['app' => ['removeProfileImage' => [
                        'id' => 'project-1',
                        'name' => 'Atlas',
                        'profileImageUrl' => null,
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProjectSummary') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'name' => 'Atlas',
                        'slug' => 'atlas',
                        'fullName' => '@acme/atlas',
                        'profileImageUrl' => null,
                    ]]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected request.']]]),
            };
        });

        (new PushExpoProject($project->id))->handle(app(ExpoPushSynchronizer::class));

        $this->assertNull($project->refresh()->icon_url);
        $this->assertSame('synced', $project->auto_sync_status);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerRemoveExpoProjectIcon',
        ));
        Http::assertSentCount(3);
    }

    /** @return array{User, Team} */
    private function member(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    private function expoIntegration(Team $team): Integration
    {
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::AppService,
            'provider' => IntegrationProvider::Expo,
            'name' => 'Expo acme',
            'status' => IntegrationStatus::Active,
            'external_id' => 'account-1',
            'external_account_id' => 'account-1',
            'external_account_name' => 'acme',
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $integration->id,
            'access_token' => 'expo-secret-token',
            'token_type' => 'Bearer',
        ]);

        return $integration;
    }

    private function project(
        Integration $integration,
        MobileApp $app,
        string $externalId = 'project-1',
    ): ExpoProject {
        return ExpoProject::create([
            'team_id' => $app->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => $externalId,
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => $app->slug,
            'name' => $app->name,
            'full_name' => '@acme/'.$app->slug,
            'exists_on_expo' => true,
        ]);
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
}
