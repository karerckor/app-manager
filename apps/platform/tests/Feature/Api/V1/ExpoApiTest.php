<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\TeamRole;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.enabled', true);
        config()->set('integrations.expo.enabled', true);
        config()->set('integrations.expo.api_url', 'https://api.expo.test');
        config()->set('integrations.expo.graphql_url', 'https://expo.test/graphql');
    }

    public function test_connects_expo_and_lists_synced_projects_without_leaking_token(): void
    {
        [$user, $team] = $this->context();
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            if (str_contains($query, 'AppManagerViewer')) {
                return Http::response(['data' => ['meActor' => [
                    'id' => 'actor-1',
                    'displayName' => 'Release Bot',
                    'accounts' => [[
                        'id' => 'account-1',
                        'name' => 'acme',
                        'displayName' => 'Acme',
                    ]],
                ]]]);
            }

            return Http::response(['data' => ['account' => ['byName' => [
                'apps' => [[
                    'id' => 'project-1',
                    'name' => 'Atlas',
                    'slug' => 'atlas',
                    'fullName' => '@acme/atlas',
                    'description' => 'Expo app',
                    'profileImageUrl' => null,
                    'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                ]],
            ]]]]);
        });
        $token = $this->token($user, $team, [ApiKeyScope::IntegrationsWrite]);

        $response = $this->withToken($token)
            ->postJson($this->api($team, '/integrations/expo'), [
                'access_token' => 'expo-secret-token',
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'expo')
            ->assertJsonPath('data.external_account_name', 'acme')
            ->assertJsonPath('meta.projects_count', 1);

        $this->assertStringNotContainsString('expo-secret-token', $response->getContent());
        $this->assertSame(
            'expo-secret-token',
            Integration::firstOrFail()->oauthCredential()->firstOrFail()->access_token,
        );

        $this->postJson($this->api($team, '/integrations/expo'), [
            'access_token' => 'rotated-expo-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', Integration::firstOrFail()->id);
        $this->assertDatabaseCount('integrations', 1);
        $this->assertSame(
            'rotated-expo-token',
            Integration::firstOrFail()->oauthCredential()->firstOrFail()->access_token,
        );

        $this->authenticate($this->token($user, $team, [ApiKeyScope::IntegrationsRead]));
        $this->getJson($this->api(
            $team,
            '/integrations/'.Integration::firstOrFail()->id.'/expo/projects',
        ))
            ->assertOk()
            ->assertJsonPath('data.0.external_id', 'project-1')
            ->assertJsonPath('data.0.full_name', '@acme/atlas');
    }

    public function test_read_scope_cannot_configure_or_mutate_expo(): void
    {
        [$user, $team] = $this->context();
        [$integration] = $this->expoIntegration($team);
        $this->withToken($this->token($user, $team, [ApiKeyScope::IntegrationsRead]));

        $this->postJson($this->api($team, '/integrations/expo'), [
            'access_token' => 'forbidden',
        ])->assertForbidden();
        $this->postJson($this->api(
            $team,
            "/integrations/{$integration->id}/expo/graphql",
        ), [
            'query' => 'mutation { forbidden }',
        ])->assertForbidden();
        $this->postJson($this->api(
            $team,
            "/integrations/{$integration->id}/expo/proxy/v2/workflows/dispatch",
        ), [])->assertForbidden();
        $this->getJson($this->api(
            $team,
            "/integrations/{$integration->id}/expo/proxy/v2/workflows/runs",
        ))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_graphql_and_rest_proxy_use_the_stored_expo_token(): void
    {
        [$user, $team] = $this->context();
        [$integration] = $this->expoIntegration($team);
        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://expo.test/graphql') {
                return Http::response(['data' => [
                    'app' => ['byId' => ['id' => 'project-1']],
                ]]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'data' => [
                    'method' => $request->method(),
                    'cursor' => $query['cursor'] ?? null,
                ],
            ], 202, [
                'X-RateLimit-Remaining' => '49',
            ]);
        });

        $this->withToken($this->token($user, $team, [ApiKeyScope::IntegrationsProxy]))
            ->postJson($this->api(
                $team,
                "/integrations/{$integration->id}/expo/graphql",
            ), [
                'query' => 'query Project($id: String!) { app { byId(appId: $id) { id } } }',
                'variables' => ['id' => 'project-1'],
                'operation_name' => 'Project',
            ])
            ->assertOk()
            ->assertJsonPath('data.app.byId.id', 'project-1');

        $this->getJson($this->api(
            $team,
            "/integrations/{$integration->id}/expo/proxy/v2/workflows/runs?cursor=next",
        ))
            ->assertStatus(202)
            ->assertHeader('X-RateLimit-Remaining', '49')
            ->assertJsonPath('data.method', 'GET')
            ->assertJsonPath('data.cursor', 'next');

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader(
            'Authorization',
            'Bearer expo-token',
        ));
        Http::assertSent(fn (HttpRequest $request) => data_get(
            $request->data(),
            'operationName',
        ) === 'Project');
    }

    public function test_imports_an_expo_project_as_a_local_application(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$user, $team] = $this->context();
        [$integration, $project] = $this->expoIntegration($team);
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => [
                    'id' => 'project-1',
                    'name' => 'Atlas',
                    'slug' => 'atlas',
                    'fullName' => '@acme/atlas',
                    'description' => 'Imported from Expo',
                    'profileImageUrl' => null,
                    'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                    'androidAppCredentials' => [[
                        'id' => 'android-credentials-1',
                        'applicationIdentifier' => 'com.example.atlas',
                        'googleServiceAccountKeyForFcmV1' => null,
                        'googleServiceAccountKeyForSubmissions' => null,
                        'androidAppBuildCredentialsList' => [],
                    ]],
                    'iosAppCredentials' => [],
                    'builds' => [],
                    'submissions' => [],
                ]]],
            ]),
        ]);
        $token = $this->token($user, $team, [
            ApiKeyScope::IntegrationsWrite,
            ApiKeyScope::AppsWrite,
            ApiKeyScope::CredentialsWrite,
        ]);

        $this->withToken($token)
            ->postJson($this->api(
                $team,
                "/integrations/{$integration->id}/expo/projects/{$project->id}/import",
            ))
            ->assertCreated()
            ->assertJsonPath('data.app.slug', 'atlas')
            ->assertJsonPath('data.project.external_id', 'project-1')
            ->assertJsonPath('meta.identifiers_imported', 1)
            ->assertJsonPath('meta.credentials_imported', 0);

        $this->assertDatabaseHas('app_identifiers', [
            'mobile_app_id' => MobileApp::firstOrFail()->id,
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.atlas',
            'is_default' => true,
        ]);
        $this->assertNotNull($project->refresh()->mobile_app_id);

        $app = MobileApp::firstOrFail();
        $app->delete();

        $this->withToken($token)
            ->postJson($this->api(
                $team,
                "/integrations/{$integration->id}/expo/projects/{$project->id}/import",
            ))
            ->assertCreated()
            ->assertJsonPath('data.app.id', $app->id)
            ->assertJsonPath('meta.restored_from_trash', true);

        $this->assertDatabaseHas('mobile_apps', [
            'id' => $app->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, MobileApp::withTrashed()->where('team_id', $team->id)->count());
    }

    public function test_identifier_sync_is_bidirectional(): void
    {
        [$user, $team] = $this->context();
        [$integration, $project] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'atlas']);
        $project->update(['mobile_app_id' => $app->id]);
        $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.local',
            'name' => 'Local',
        ]);
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerExpoProjectIdentifiers') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'androidAppCredentials' => [[
                            'id' => 'android-remote',
                            'applicationIdentifier' => 'com.example.remote',
                        ]],
                        'iosAppCredentials' => [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'androidAppCredentials' => [[
                            'id' => 'android-remote',
                            'applicationIdentifier' => 'com.example.remote',
                        ]],
                        'iosAppCredentials' => [],
                        'builds' => [],
                        'submissions' => [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateAndroidAppCredentials') => Http::response([
                    'data' => ['androidAppCredentials' => [
                        'createAndroidAppCredentials' => ['id' => 'android-local'],
                    ]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected operation']]], 400),
            };
        });

        $this->withToken($this->token($user, $team, [
            ApiKeyScope::IdentifiersWrite,
            ApiKeyScope::IntegrationsWrite,
        ]))
            ->postJson($this->api(
                $team,
                "/apps/{$app->slug}/expo/identifiers/sync",
            ))
            ->assertOk()
            ->assertJsonPath('data.imported_from_expo', 1)
            ->assertJsonPath('data.pushed_to_expo', 1)
            ->assertJsonCount(0, 'data.errors');

        $this->assertDatabaseHas('app_identifiers', [
            'mobile_app_id' => $app->id,
            'identifier' => 'com.example.remote',
        ]);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerCreateAndroidAppCredentials',
        ));
    }

    public function test_provisions_an_identifier_and_attached_credentials_on_expo(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$user, $team] = $this->context();
        [, $project] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'atlas']);
        $project->update(['mobile_app_id' => $app->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Android',
        ]);
        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Google Play',
            'payload' => [],
        ]);
        $credential->addMediaFromString(json_encode([
            'type' => 'service_account',
            'client_email' => 'release@acme.test',
            'private_key' => 'secret',
        ], JSON_THROW_ON_ERROR))
            ->usingFileName('google-play.json')
            ->toMediaCollection('file');
        $identifier->credentials()->attach($credential);
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerCreateGoogleServiceAccountKey') => Http::response([
                    'data' => ['googleServiceAccountKey' => [
                        'createGoogleServiceAccountKey' => [
                            'id' => 'service-account-1',
                            'clientEmail' => 'release@acme.test',
                            'projectIdentifier' => 'acme',
                        ],
                    ]],
                ]),
                str_contains($query, 'AppManagerAttachGoogleServiceAccountKey') => Http::response([
                    'data' => ['androidAppCredentials' => [
                        'setGoogleServiceAccountKeyForSubmissions' => ['id' => 'android-1'],
                    ]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'androidAppCredentials' => [[
                            'id' => 'android-1',
                            'applicationIdentifier' => 'com.example.atlas',
                        ]],
                        'iosAppCredentials' => [],
                        'builds' => [],
                        'submissions' => [],
                    ]]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected operation']]], 400),
            };
        });

        $this->withToken($this->token($user, $team, [
            ApiKeyScope::IdentifiersWrite,
            ApiKeyScope::CredentialsWrite,
            ApiKeyScope::IntegrationsWrite,
        ]))
            ->postJson($this->api(
                $team,
                "/apps/{$app->slug}/expo/identifiers/{$identifier->id}/provision",
            ), [
                'credential_ids' => [$credential->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.identifier.remote_resource_id', 'android-1')
            ->assertJsonPath('data.credentials.0.remote_resource_id', 'service-account-1')
            ->assertJsonCount(0, 'data.errors');

        $this->assertDatabaseHas('expo_credential_links', [
            'expo_project_id' => $project->id,
            'credential_id' => $credential->id,
            'app_identifier_id' => $identifier->id,
            'remote_resource_id' => 'service-account-1',
        ]);
    }

    /** @return array{User, Team} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);

        return [$user, $team];
    }

    /** @return array{Integration, ExpoProject} */
    private function expoIntegration(Team $team): array
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
        app(OAuthCredentialStore::class)->put(
            $integration,
            new OAuthTokens('expo-token', tokenType: 'Bearer'),
        );
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'description' => 'Expo app',
            'exists_on_expo' => true,
        ]);

        return [$integration, $project];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function token(User $user, Team $team, array $scopes): string
    {
        return $user->createTeamApiKey(
            $team,
            'Expo API test',
            collect($scopes)->map->value->all(),
        )->plainTextToken;
    }

    private function authenticate(string $token): void
    {
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }

    private function api(Team $team, string $path): string
    {
        return "/api/v1/{$team->slug}{$path}";
    }
}
