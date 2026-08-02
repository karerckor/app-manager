<?php

namespace Tests\Feature\Integrations;

use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Jobs\Integrations\ImportExpoProject;
use App\Models\Credential;
use App\Models\ExpoCredentialLink;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Apps\CredentialPresenter;
use App\Support\Credentials\CredentialFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpoIntegrationTest extends TestCase
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

    public function test_expo_token_connects_one_account_and_syncs_projects(): void
    {
        [$owner, $team] = $this->member();
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

            return Http::response([
                'data' => [
                    'account' => [
                        'byName' => [
                            'apps' => [[
                                'id' => 'project-1',
                                'name' => 'Atlas Mobile',
                                'slug' => 'atlas',
                                'fullName' => '@acme/atlas',
                                'description' => 'Expo project',
                                'profileImageUrl' => 'https://cdn.expo.test/atlas.png',
                                'updated' => now()->toIso8601String(),
                                'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                            ]],
                        ],
                    ],
                ],
            ]);
        });

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.store', $team), [
                'access_token' => 'expo-secret-token',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo account connected.');

        $integration = Integration::firstOrFail();
        $credential = IntegrationOAuthCredential::firstOrFail();
        $project = ExpoProject::firstOrFail();

        $this->assertSame(IntegrationKind::AppService, $integration->kind);
        $this->assertSame(IntegrationProvider::Expo, $integration->provider);
        $this->assertSame('account-1', $integration->external_account_id);
        $this->assertSame('expo-secret-token', $credential->access_token);
        $this->assertNotSame('expo-secret-token', $credential->getRawOriginal('access_token'));
        $this->assertSame('project-1', $project->external_id);
        $this->assertNull($project->mobile_app_id);
        $this->assertSame('https://cdn.expo.test/atlas.png', $project->icon_url);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://expo.test/graphql'
            && $request->hasHeader('Authorization', 'Bearer expo-secret-token'));
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'profileImageUrl(width: EXTRA_LARGE)',
        ));
    }

    public function test_account_name_is_required_when_token_has_multiple_accounts(): void
    {
        [$owner, $team] = $this->member();
        Http::fake([
            'https://expo.test/graphql' => Http::response(['data' => ['meActor' => [
                'id' => 'actor-1',
                'displayName' => 'Owner',
                'accounts' => [
                    ['id' => 'account-1', 'name' => 'acme', 'displayName' => 'Acme'],
                    ['id' => 'account-2', 'name' => 'labs', 'displayName' => 'Labs'],
                ],
            ]]]),
        ]);

        $this->actingAs($owner)
            ->from(route('accounts.integrations.eas.index', $team))
            ->post(route('accounts.integrations.expo.store', $team), [
                'access_token' => 'expo-secret-token',
            ])
            ->assertRedirect(route('accounts.integrations.eas.index', $team))
            ->assertSessionHasErrors('account_name')
            ->assertSessionMissing('_old_input.access_token');

        $this->assertDatabaseCount('integrations', 0);
        $this->assertDatabaseCount('integration_oauth_credentials', 0);
    }

    public function test_disconnecting_expo_preserves_and_surfaces_the_inactive_app_link(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
            'version' => '1.0.0',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
        ]);
        $identifier->credentials()->attach($credential);
        ExpoCredentialLink::create([
            'integration_id' => $integration->id,
            'expo_project_id' => $project->id,
            'credential_id' => $credential->id,
            'app_identifier_id' => $identifier->id,
            'remote_resource_type' => 'IosDistributionCertificate',
            'remote_resource_id' => 'expo-credential-1',
        ]);

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.expo.disconnect', [$team, $integration]))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Expo disconnected. Project links and remote history were preserved.',
            );

        $this->assertSame(IntegrationStatus::Disconnected, $integration->refresh()->status);
        $this->assertDatabaseMissing('integration_oauth_credentials', [
            'integration_id' => $integration->id,
        ]);
        $this->assertDatabaseHas('expo_projects', [
            'id' => $project->id,
            'mobile_app_id' => $app->id,
        ]);
        $this->assertDatabaseHas('expo_credential_links', [
            'id' => ExpoCredentialLink::firstOrFail()->id,
        ]);

        $this->get(route('accounts.integrations.eas.index', [
            $team,
        ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->has('expoIntegration.accounts', 0));

        $this->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Overview')
                ->where(
                    'app.urls.expo_page',
                    route('accounts.apps.expo.index', [$team, $app]),
                )
                ->where('expo.enabled', true)
                ->where('expo.linked', false)
                ->where('expo.connected_accounts', 0)
                ->where('expo.project', null)
                ->where('expo.inactive_project.full_name', '@acme/atlas')
                ->where('expo.inactive_project.account_name', 'acme')
                ->where('expo.inactive_project.connection_status', 'disconnected'));

        $this->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Show')
                ->where(
                    'app.urls.expo_page',
                    route('accounts.apps.expo.index', [$team, $app]),
                )
                ->where('identifier.services.0.expo.available', false)
                ->where('identifier.services.0.expo.pushed', false)
                ->where('identifier.services.0.expo.push_url', null));

        $this->get(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Expo/Index')
                ->where('expo.linked', false)
                ->where('expo.connected_accounts', 0)
                ->where('expo.inactive_project.full_name', '@acme/atlas')
                ->where('expo.inactive_project.connection_status', 'disconnected')
                ->has('expo.accounts', 0));
    }

    public function test_inactive_expo_link_can_be_replaced_with_a_project_from_another_account(): void
    {
        [$owner, $team] = $this->member();
        [$inactiveIntegration] = $this->expoIntegration($team);
        $inactiveIntegration->update([
            'status' => IntegrationStatus::Disconnected,
            'disconnected_at' => now(),
        ]);
        $inactiveIntegration->oauthCredential()->delete();

        $activeIntegration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::AppService,
            'provider' => IntegrationProvider::Expo,
            'name' => 'Expo labs',
            'status' => IntegrationStatus::Active,
            'external_id' => 'account-2',
            'external_account_id' => 'account-2',
            'external_account_name' => 'labs',
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $activeIntegration->id,
            'access_token' => 'expo-labs-token',
            'token_type' => 'Bearer',
        ]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $inactiveProject = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $inactiveIntegration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'auto_sync_enabled' => true,
            'auto_sync_status' => 'queued',
            'auto_sync_pending_at' => now(),
            'exists_on_expo' => true,
        ]);
        $replacementProject = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $activeIntegration->id,
            'external_id' => 'project-2',
            'account_id' => 'account-2',
            'account_name' => 'labs',
            'slug' => 'atlas-next',
            'name' => 'Atlas Next',
            'full_name' => '@labs/atlas-next',
            'exists_on_expo' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expo.linked', false)
                ->where('expo.connected_accounts', 1)
                ->where('expo.inactive_project.full_name', '@acme/atlas')
                ->where('expo.inactive_project.connection_status', 'disconnected')
                ->has('expo.accounts', 1)
                ->where('expo.accounts.0.name', 'labs'));

        $this->post(route('accounts.apps.expo.setup', [$team, $app]), [
            'integration_id' => $activeIntegration->id,
            'mode' => 'existing',
            'expo_project_id' => $replacementProject->id,
            'sync_metadata' => false,
        ])
            ->assertRedirect(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSessionHas(
                'status',
                'Application set up on Expo. The previous local Expo link was replaced; nothing was deleted on Expo.',
            );

        $this->assertNull($inactiveProject->refresh()->mobile_app_id);
        $this->assertFalse($inactiveProject->auto_sync_enabled);
        $this->assertSame('idle', $inactiveProject->auto_sync_status);
        $this->assertNull($inactiveProject->auto_sync_pending_at);
        $this->assertSame($app->id, $replacementProject->refresh()->mobile_app_id);
    }

    public function test_identifier_index_compares_database_and_expo_identifiers(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.shared',
            'name' => 'Shared Android',
        ]);
        $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.database',
            'name' => 'Database iOS',
        ]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => [
                    'id' => 'project-1',
                    'androidAppCredentials' => [[
                        'id' => 'android-credentials-1',
                        'applicationIdentifier' => 'com.example.shared',
                    ]],
                    'iosAppCredentials' => [[
                        'id' => 'ios-credentials-1',
                        'appleAppIdentifier' => [
                            'id' => 'apple-identifier-1',
                            'bundleIdentifier' => 'com.example.expo',
                        ],
                    ]],
                ]]],
            ]),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.apps.identifiers.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Index')
                ->has('identifiers', 3)
                ->where('identifiers.0.identifier', 'com.example.shared')
                ->where('identifiers.0.sync_status', 'synced')
                ->where('identifiers.0.sources.database', true)
                ->where('identifiers.0.sources.expo', true)
                ->where('identifiers.1.identifier', 'com.example.database')
                ->where('identifiers.1.sync_status', 'database_only')
                ->where('identifiers.1.sources.database', true)
                ->where('identifiers.1.sources.expo', false)
                ->where('identifiers.2.identifier', 'com.example.expo')
                ->where('identifiers.2.sync_status', 'expo_only')
                ->where('identifiers.2.sources.database', false)
                ->where('identifiers.2.sources.expo', true)
                ->where('identifiers.2.show_url', null)
                ->where('expoIdentifiers.linked', true)
                ->where('expoIdentifiers.comparison_available', true)
                ->where('expoIdentifiers.error', null)
                ->where('expoIdentifiers.can_sync', true)
                ->where(
                    'expoIdentifiers.sync_url',
                    route('accounts.apps.identifiers.sync', [$team, $app]),
                ));

        Http::assertSent(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return str_contains($query, 'AppManagerExpoProjectIdentifiers')
                && ! str_contains($query, 'keyJson')
                && ! str_contains($query, 'builds(')
                && ! str_contains($query, 'submissions(');
        });
    }

    public function test_identifier_sync_imports_expo_only_and_pushes_database_only_identifiers(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $local = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.database',
            'name' => 'Database Android',
        ]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerExpoProjectIdentifiers') => Http::response([
                    'data' => ['app' => ['byId' => [
                        'id' => 'project-1',
                        'androidAppCredentials' => [],
                        'iosAppCredentials' => [[
                            'id' => 'ios-credentials-1',
                            'appleAppIdentifier' => [
                                'id' => 'apple-identifier-1',
                                'bundleIdentifier' => 'com.example.expo',
                            ],
                        ]],
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        ...$this->remoteProject(),
                        'androidAppCredentials' => [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateAndroidAppCredentials') => Http::response([
                    'data' => ['androidAppCredentials' => ['createAndroidAppCredentials' => [
                        'id' => 'android-credentials-new',
                    ]]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected Expo request.']]]),
            };
        });
        $indexUrl = route('accounts.apps.identifiers.index', [$team, $app]);

        $this->actingAs($owner)
            ->from($indexUrl)
            ->post(route('accounts.apps.identifiers.sync', [$team, $app]))
            ->assertRedirect($indexUrl)
            ->assertSessionHas(
                'status',
                'Identifiers synced: 1 imported from Expo, 1 pushed to Expo.',
            );

        $this->assertDatabaseHas('app_identifiers', [
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.expo',
            'name' => 'iOS',
        ]);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerCreateAndroidAppCredentials',
        ) && data_get(
            $request->data(),
            'variables.applicationIdentifier',
        ) === $local->identifier);
    }

    public function test_integration_page_paginates_expo_projects_and_keeps_expo_tab_active(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);

        foreach (range(1, 25) as $index) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            ExpoProject::create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'external_id' => "project-{$number}",
                'account_id' => 'account-1',
                'account_name' => 'acme',
                'slug' => "project-{$number}",
                'name' => "Project {$number}",
                'full_name' => "@acme/project-{$number}",
                'icon_url' => "https://cdn.expo.test/project-{$number}.png",
                'exists_on_expo' => true,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('accounts.integrations.eas.index', [
                $team,
                'expo_search' => 'Project',
                'expo_integration' => $integration->id,
                'expo_page' => 2,
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->where('activeTab', 'expo')
                ->has('expoIntegration.accounts', 1)
                ->has('expoIntegration.accounts.0.projects', 5)
                ->where('expoIntegration.accounts.0.projects.0.name', 'Project 21')
                ->where('expoIntegration.accounts.0.projects.0.project_id', 'project-21')
                ->where(
                    'expoIntegration.accounts.0.projects.0.icon_url',
                    'https://cdn.expo.test/project-21.png',
                )
                ->where('expoIntegration.accounts.0.pagination.page', 2)
                ->where('expoIntegration.accounts.0.pagination.last_page', 2)
                ->where('expoIntegration.accounts.0.pagination.per_page', 20)
                ->where('expoIntegration.accounts.0.pagination.from', 21)
                ->where('expoIntegration.accounts.0.pagination.to', 25)
                ->where('expoIntegration.accounts.0.pagination.total', 25)
                ->where('expoIntegration.accounts.0.pagination.previous_url', route(
                    'accounts.integrations.eas.index',
                    [
                        $team,
                        'expo_search' => 'Project',
                        'expo_integration' => $integration->id,
                        'expo_page' => 1,
                    ],
                ))
                ->where('expoIntegration.accounts.0.pagination.next_url', null));
    }

    public function test_bulk_import_queues_each_selected_available_expo_project(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $first = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'expo-project-1',
            'account_id' => 'expo-account-1',
            'account_name' => 'acme',
            'slug' => 'first',
            'name' => 'First',
            'full_name' => '@acme/first',
            'exists_on_expo' => true,
        ]);
        $second = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'expo-project-2',
            'account_id' => 'expo-account-1',
            'account_name' => 'acme',
            'slug' => 'second',
            'name' => 'Second',
            'full_name' => '@acme/second',
            'exists_on_expo' => true,
        ]);
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import-batch', [$team, $integration]), [
                'project_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 Expo project(s) queued for import.');

        Queue::assertPushed(ImportExpoProject::class, 2);
        Queue::assertPushed(ImportExpoProject::class, function ($job) use ($team, $first, $second) {
            return $job->teamId === $team->id
                && in_array($job->projectId, [$first->id, $second->id], true)
                && $job->queue === 'integrations';
        });
    }

    public function test_integration_page_searches_expo_projects_by_name_slug_or_project_id(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);

        foreach ([
            ['external_id' => 'atlas-id', 'slug' => 'atlas-mobile', 'name' => 'Atlas Mobile'],
            ['external_id' => 'expo-project-comet-42', 'slug' => 'release-channel', 'name' => 'Comet'],
        ] as $project) {
            ExpoProject::create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'account_id' => 'account-1',
                'account_name' => 'acme',
                'full_name' => "@acme/{$project['slug']}",
                'exists_on_expo' => true,
                ...$project,
            ]);
        }

        foreach ([
            'ATLAS MOBILE' => 'Atlas Mobile',
            'release-channel' => 'Comet',
            'comet-42' => 'Comet',
        ] as $search => $expectedName) {
            $this->actingAs($owner)
                ->get(route('accounts.integrations.eas.index', [
                    $team,
                    'expo_search' => $search,
                ]))
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('activeTab', 'expo')
                    ->where('expoIntegration.search', $search)
                    ->has('expoIntegration.accounts.0.projects', 1)
                    ->where('expoIntegration.accounts.0.projects.0.name', $expectedName)
                    ->where('expoIntegration.accounts.0.pagination.total', 1));
        }
    }

    public function test_provided_account_name_uses_direct_account_lookup(): void
    {
        [$owner, $team] = $this->member();
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            if (str_contains($query, 'AppManagerExpoAccountByName')) {
                return Http::response(['data' => ['account' => ['byName' => [
                    'id' => 'account-1',
                    'name' => 'acme',
                    'displayName' => 'Acme',
                ]]]]);
            }

            return Http::response([
                'data' => ['account' => ['byName' => ['apps' => []]]],
            ]);
        });

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.store', $team), [
                'access_token' => 'expo-secret-token',
                'account_name' => 'acme',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo account connected.');

        $this->assertDatabaseHas('integrations', [
            'provider' => IntegrationProvider::Expo->value,
            'external_account_id' => 'account-1',
            'external_account_name' => 'acme',
        ]);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerViewer',
        ));
    }

    public function test_degraded_integration_can_sync_projects_by_account_name(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $integration->update([
            'status' => IntegrationStatus::Degraded,
            'settings' => [
                'last_sync_error' => 'Previous Expo sync failed.',
                'last_sync_failed_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);
        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            if (str_contains($query, 'AppManagerExpoProjects')) {
                return Http::response(['data' => ['account' => ['byName' => ['apps' => [[
                    'id' => 'project-1',
                    'name' => 'Atlas',
                    'slug' => 'atlas',
                    'fullName' => '@acme/atlas',
                    'description' => 'Expo project',
                    'profileImageUrl' => null,
                    'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                ]]]]]]);
            }

            return Http::response(['data' => ['account' => ['byName' => [
                'id' => 'account-1',
                'name' => 'acme',
                'displayName' => 'Acme',
            ]]]]);
        });

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.sync', [$team, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo projects synced.');

        $integration->refresh();
        $this->assertSame(IntegrationStatus::Active, $integration->status);
        $this->assertNotNull($integration->last_synced_at);
        $this->assertArrayNotHasKey('last_sync_error', $integration->settings);
        $this->assertArrayNotHasKey('last_sync_failed_at', $integration->settings);
        $this->assertDatabaseHas('expo_projects', [
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'full_name' => '@acme/atlas',
        ]);
        Http::assertSent(fn (HttpRequest $request) => data_get(
            $request->data(),
            'variables.accountName',
        ) === 'acme' && data_get(
            $request->data(),
            'variables.limit',
        ) === 50);
    }

    public function test_expo_http_graphql_error_is_returned_to_the_connection_form(): void
    {
        [$owner, $team] = $this->member();
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'errors' => [[
                    'message' => 'Cannot query field "accounts" on type "Actor".',
                ]],
            ], 400),
        ]);

        $this->actingAs($owner)
            ->from(route('accounts.integrations.eas.index', $team))
            ->post(route('accounts.integrations.expo.store', $team), [
                'access_token' => 'expo-secret-token',
                'account_name' => 'acme',
            ])
            ->assertRedirect(route('accounts.integrations.eas.index', $team))
            ->assertSessionHasErrors([
                'access_token' => 'Expo HTTP 400: Cannot query field "accounts" on type "Actor".',
            ]);

        $this->assertDatabaseCount('integrations', 0);
        $this->assertDatabaseCount('integration_oauth_credentials', 0);
    }

    public function test_existing_application_can_link_an_existing_expo_project(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Local name',
            'description' => 'Local description',
        ]);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Expo name',
            'full_name' => '@acme/atlas',
            'description' => 'Expo description',
            'exists_on_expo' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.setup', [$team, $app]), [
                'integration_id' => $integration->id,
                'mode' => 'existing',
                'expo_project_id' => $project->id,
                'sync_metadata' => true,
            ])
            ->assertRedirect(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSessionHas('status', 'Application set up on Expo.');

        $this->assertSame($app->id, $project->refresh()->mobile_app_id);
        $this->assertSame('Expo name', $app->refresh()->name);
        $this->assertSame('Expo description', $app->description);
    }

    public function test_setup_can_create_a_new_expo_project_without_affecting_other_apps(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Atlas Mobile',
            'slug' => 'atlas',
        ]);
        $other = MobileApp::factory()->create(['team_id' => $team->id]);
        Http::fake([
            'https://expo.test/graphql' => Http::response(['data' => ['app' => ['createApp' => [
                'id' => 'project-new',
                'name' => 'Atlas Mobile',
                'slug' => 'atlas',
                'fullName' => '@acme/atlas',
                'description' => null,
                'profileImageUrl' => null,
                'updated' => now()->toIso8601String(),
                'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
            ]]]]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.setup', [$team, $app]), [
                'integration_id' => $integration->id,
                'mode' => 'create',
                'sync_metadata' => false,
            ])
            ->assertRedirect(route('accounts.apps.expo.index', [$team, $app]));

        $project = ExpoProject::firstOrFail();
        $this->assertSame($app->id, $project->mobile_app_id);
        $this->assertSame('project-new', $project->external_id);
        $this->assertFalse($project->sync_metadata);
        $this->assertFalse($other->expoProject()->exists());
        Http::assertSent(fn (HttpRequest $request) => data_get($request->data(), 'variables.input') === [
            'accountId' => 'account-1',
            'projectName' => 'atlas',
            'appInfo' => ['displayName' => 'Atlas Mobile'],
        ]);
    }

    public function test_import_downloads_identifiers_credential_secrets_and_files(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'description' => 'Expo project',
            'exists_on_expo' => true,
        ]);
        $remote = $this->remoteProject();
        $remote['androidAppCredentials'][0] = [
            'id' => 'android-creds-1',
            'applicationIdentifier' => 'com.example.atlas',
            'googleServiceAccountKeyForFcmV1' => [
                'id' => 'fcm-gsa-1',
                'clientEmail' => 'firebase@acme.iam.gserviceaccount.com',
                'projectIdentifier' => 'acme',
                'updatedAt' => now()->toIso8601String(),
                'keyJson' => '{"type":"service_account","private_key":"fcm-secret"}',
            ],
            'googleServiceAccountKeyForSubmissions' => [
                'id' => 'play-gsa-1',
                'clientEmail' => 'release@acme.iam.gserviceaccount.com',
                'projectIdentifier' => 'acme',
                'updatedAt' => now()->toIso8601String(),
                'keyJson' => '{"type":"service_account","private_key":"play-secret"}',
            ],
            'androidAppBuildCredentialsList' => [[
                'id' => 'android-build-creds-1',
                'name' => 'Production',
                'isDefault' => true,
                'androidKeystore' => [
                    'id' => 'keystore-1',
                    'keyAlias' => 'release',
                    'md5CertificateFingerprint' => 'MD5',
                    'sha1CertificateFingerprint' => 'SHA1',
                    'sha256CertificateFingerprint' => 'SHA256',
                    'updatedAt' => now()->toIso8601String(),
                    'keystore' => base64_encode('keystore-secret-bytes'),
                    'keystorePassword' => 'store-secret',
                    'keyPassword' => 'key-secret',
                ],
            ]],
        ];
        $remote['iosAppCredentials'] = [[
            'id' => 'ios-creds-1',
            'appleAppIdentifier' => [
                'id' => 'apple-app-id-1',
                'bundleIdentifier' => 'com.example.atlas.ios',
            ],
            'appleTeam' => null,
            'pushKey' => [
                'id' => 'push-key-1',
                'keyIdentifier' => 'PUSHKEY123',
                'updatedAt' => now()->toIso8601String(),
                'appleTeam' => [
                    'id' => 'apple-team-1',
                    'appleTeamIdentifier' => 'TEAMID1234',
                    'appleTeamName' => 'Acme',
                ],
                'keyP8' => "-----BEGIN PRIVATE KEY-----\napns-secret\n-----END PRIVATE KEY-----",
            ],
            'appStoreConnectApiKeyForSubmissions' => [
                'id' => 'asc-key-1',
                'name' => 'Submissions',
                'keyIdentifier' => 'ASCKEY1234',
                'issuerIdentifier' => '019fab79-f62b-7ab0-a84d-1ee8b3d61582',
                'updatedAt' => now()->toIso8601String(),
                'keyP8' => "-----BEGIN PRIVATE KEY-----\nasc-secret\n-----END PRIVATE KEY-----",
            ],
            'iosAppBuildCredentialsList' => [[
                'id' => 'ios-build-creds-1',
                'iosDistributionType' => 'APP_STORE',
                'distributionCertificate' => [
                    'id' => 'certificate-1',
                    'serialNumber' => '123456',
                    'validityNotAfter' => now()->addYear()->toIso8601String(),
                    'updatedAt' => now()->toIso8601String(),
                    'certificateP12' => base64_encode('certificate-secret-bytes'),
                    'certificatePassword' => 'certificate-secret',
                ],
                'provisioningProfile' => [
                    'id' => 'profile-1',
                    'appleUUID' => 'profile-uuid',
                    'expiration' => now()->addMonths(6)->toIso8601String(),
                    'status' => 'ACTIVE',
                    'updatedAt' => now()->toIso8601String(),
                    'provisioningProfile' => base64_encode('provisioning-profile-secret-bytes'),
                ],
            ]],
        ]];
        $remote['builds'][] = [
            'id' => 'build-ios-1',
            'platform' => 'IOS',
            'status' => 'FINISHED',
            'buildProfile' => 'production',
            'distribution' => 'STORE',
            'appIdentifier' => 'com.example.atlas.ios',
            'appVersion' => '2.1.0',
            'appBuildVersion' => '42',
        ];
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import', [
                $team,
                $integration,
                $project,
            ]))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Expo project imported with 2 identifiers and 7 credentials.',
            );

        $app = MobileApp::firstOrFail();
        $this->assertSame($app->id, $project->refresh()->mobile_app_id);
        $this->assertDatabaseHas('app_identifiers', [
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.atlas',
            'version' => '1.0.0',
            'version_code' => 1,
        ]);
        $this->assertDatabaseHas('app_identifiers', [
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.atlas.ios',
            'version' => '2.1.0',
            'version_code' => null,
        ]);
        $this->assertDatabaseCount('credentials', 7);
        $this->assertDatabaseCount('expo_credential_links', 7);
        $this->assertDatabaseCount('media', 7);
        $this->assertSame(7, Credential::query()->whereNotNull('fingerprint')->count());

        $credentials = Credential::all();
        $this->assertEqualsCanonicalizing([
            CredentialType::AndroidKeystore,
            CredentialType::FirebaseConfig,
            CredentialType::GooglePlayServiceAccount,
            CredentialType::ApnsKey,
            CredentialType::AppleApiKey,
            CredentialType::IosCertificate,
            CredentialType::ProvisioningProfile,
        ], $credentials->pluck('type')->all());
        foreach ($credentials as $credential) {
            $this->assertSame([
                ['Imported from Expo', '#6366f1'],
                ['Secret synced from Expo', '#10b981'],
            ], $credential->tags);
            $this->assertTrue($credential->hasMedia('file'));
        }
        $byType = $credentials->keyBy(fn (Credential $credential) => $credential->type->value);
        $this->assertCredentialFile(
            $byType[CredentialType::AndroidKeystore->value],
            'expo-android-keystore.jks',
            'keystore-secret-bytes',
        );
        $this->assertSame([
            'store_password' => 'store-secret',
            'key_alias' => 'release',
            'key_password' => 'key-secret',
        ], $byType[CredentialType::AndroidKeystore->value]->payload);
        $this->assertCredentialFile(
            $byType[CredentialType::FirebaseConfig->value],
            'expo-fcm-service-account.json',
            '{"type":"service_account","private_key":"fcm-secret"}',
        );
        $this->assertCredentialFile(
            $byType[CredentialType::GooglePlayServiceAccount->value],
            'expo-google-play-service-account.json',
            '{"type":"service_account","private_key":"play-secret"}',
        );
        $this->assertCredentialFile(
            $byType[CredentialType::ApnsKey->value],
            'expo-apns-key.p8',
            "-----BEGIN PRIVATE KEY-----\napns-secret\n-----END PRIVATE KEY-----",
        );
        $this->assertSame([
            'key_id' => 'PUSHKEY123',
            'team_id' => 'TEAMID1234',
        ], $byType[CredentialType::ApnsKey->value]->payload);
        $this->assertCredentialFile(
            $byType[CredentialType::AppleApiKey->value],
            'expo-app-store-connect-key.p8',
            "-----BEGIN PRIVATE KEY-----\nasc-secret\n-----END PRIVATE KEY-----",
        );
        $this->assertCredentialFile(
            $byType[CredentialType::IosCertificate->value],
            'expo-distribution-certificate.p12',
            'certificate-secret-bytes',
        );
        $this->assertSame(
            ['password' => 'certificate-secret'],
            $byType[CredentialType::IosCertificate->value]->payload,
        );
        $this->assertCredentialFile(
            $byType[CredentialType::ProvisioningProfile->value],
            'expo-provisioning-profile.mobileprovision',
            'provisioning-profile-secret-bytes',
        );

        $android = $app->appIdentifiers()->where('platform', Platform::Android)->firstOrFail();
        $ios = $app->appIdentifiers()->where('platform', Platform::Ios)->firstOrFail();
        $this->assertCount(2, $android->credentials);
        $this->assertCount(2, $ios->credentials);
        $this->assertCount(
            1,
            $android->profiles()->where('type', ProfileType::Production)->firstOrFail()->credentials,
        );
        $this->assertCount(
            2,
            $ios->profiles()->where('type', ProfileType::AppStore)->firstOrFail()->credentials,
        );

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.sync', [$team, $app]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo project synced.');
        $this->assertDatabaseCount('app_identifiers', 2);
        $this->assertDatabaseCount('credentials', 7);
        $this->assertDatabaseCount('expo_credential_links', 7);
        $this->assertDatabaseCount('media', 7);

        Http::assertSent(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return str_contains($query, 'AppManagerExpoProject')
                && str_contains($query, 'keyP8')
                && str_contains($query, 'keyJson')
                && str_contains($query, 'certificateP12')
                && preg_match('/pushKey\s*\{.*?appleTeam\s*\{/s', $query) === 1
                && data_get(
                    $request->data(),
                    'variables.includeCredentialSecrets',
                ) === true;
        });
    }

    public function test_import_records_webhooks_for_created_app_identifiers_and_credentials(): void
    {
        config()->set('integrations.webhooks.enabled', true);
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'description' => 'Expo project',
            'exists_on_expo' => true,
        ]);
        $remote = $this->remoteProject();
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import', [
                $team,
                $integration,
                $project,
            ]))
            ->assertRedirect();

        $app = MobileApp::sole();
        $identifier = $app->appIdentifiers()->sole();
        $credential = Credential::sole();

        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::MobileAppCreated->value,
            'aggregate_id' => $app->id,
            'actor_user_id' => $owner->id,
            'source' => 'web',
        ]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppIdentifierCreated->value,
            'aggregate_id' => $identifier->id,
            'actor_user_id' => $owner->id,
            'source' => 'web',
        ]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::CredentialCreated->value,
            'aggregate_id' => $credential->id,
            'actor_user_id' => $owner->id,
            'source' => 'web',
        ]);
        $this->assertDatabaseCount('webhook_events', 3);

        foreach (WebhookEvent::all() as $event) {
            $this->assertSame('user', $event->payload['actor']['type']);
            $this->assertSame($owner->id, $event->payload['actor']['id']);
            $this->assertSame([$app->id], $event->filter_context['mobile_app_ids']);
        }

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.sync', [$team, $app]))
            ->assertRedirect();

        $this->assertDatabaseCount('webhook_events', 3);
    }

    public function test_import_reuses_a_matching_vault_credential_when_uniqueness_is_enabled(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        $contents = '{"type":"service_account","private_key":"play-secret"}';
        $existing = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Shared Google Play credential',
            'payload' => [],
        ]);
        $existing->addMediaFromString($contents)
            ->usingFileName('google-play.json')
            ->toMediaCollection('file');
        $remote = $this->remoteProject();
        $remote['androidAppCredentials'][0]['googleServiceAccountKeyForSubmissions']['keyJson'] = $contents;
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import', [
                $team,
                $integration,
                $project,
            ]))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Expo project imported with 1 identifiers and 1 credentials.',
            );

        $this->assertTrue($team->fresh()->check_credential_uniqueness_by_default);
        $this->assertDatabaseCount('credentials', 1);
        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('expo_credential_links', [
            'expo_project_id' => $project->id,
            'credential_id' => $existing->id,
            'remote_resource_type' => 'GoogleServiceAccountKey',
            'remote_resource_id' => 'gsa-1',
        ]);
        $this->assertSame(
            'Shared Google Play credential',
            $existing->fresh()->name,
        );
        $this->assertNotNull($existing->fresh()->fingerprint);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::CredentialUpdated->value,
            'aggregate_id' => $existing->id,
        ]);
        $this->assertDatabaseMissing('webhook_events', [
            'event_type' => WebhookEventType::CredentialCreated->value,
            'aggregate_id' => $existing->id,
        ]);
        $this->assertTrue(
            MobileApp::sole()
                ->appIdentifiers()
                ->sole()
                ->credentials()
                ->whereKey($existing->id)
                ->exists(),
        );
    }

    public function test_import_keeps_a_matching_vault_credential_separate_when_uniqueness_is_disabled(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $team->update(['check_credential_uniqueness_by_default' => false]);
        [$integration] = $this->expoIntegration($team);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        $contents = '{"type":"service_account","private_key":"play-secret"}';
        $existing = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Shared Google Play credential',
            'payload' => [],
        ]);
        $existing->addMediaFromString($contents)
            ->usingFileName('google-play.json')
            ->toMediaCollection('file');
        $remote = $this->remoteProject();
        $remote['androidAppCredentials'][0]['googleServiceAccountKeyForSubmissions']['keyJson'] = $contents;
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import', [
                $team,
                $integration,
                $project,
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('credentials', 2);
        $this->assertDatabaseCount('media', 2);
        $this->assertDatabaseMissing('expo_credential_links', [
            'expo_project_id' => $project->id,
            'credential_id' => $existing->id,
        ]);
        $this->assertSame(
            1,
            Credential::query()->distinct()->count('fingerprint'),
        );
    }

    public function test_import_downloads_the_expo_project_icon_as_the_app_icon(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'icon_url' => 'https://cdn.expo.test/atlas.png',
            'exists_on_expo' => true,
        ]);
        $remote = $this->remoteProject();
        $remote['profileImageUrl'] = $project->icon_url;
        $icon = UploadedFile::fake()->image('atlas.png', 128, 128);
        $iconContents = (string) file_get_contents($icon->getRealPath());
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
            $project->icon_url => Http::response($iconContents, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($iconContents),
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.projects.import', [
                $team,
                $integration,
                $project,
            ]))
            ->assertRedirect();

        $app = MobileApp::firstOrFail();
        $media = $app->getFirstMedia('app_icon');
        $this->assertNotNull($media);
        $this->assertSame('expo-app-icon.png', $media->file_name);
        $this->assertSame(
            $iconContents,
            Storage::disk($media->disk)->get($media->getPathRelativeToRoot()),
        );
        Http::assertSent(fn (HttpRequest $request) => $request->url() === $project->icon_url);
    }

    public function test_expo_page_does_not_request_credential_secret_values(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
        ]);
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $this->remoteProject()]],
            ]),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Expo/Index')
                ->where('expo.linked', true)
                ->where('expo.project.full_name', '@acme/atlas')
                ->where('expo.remote.activity.active', 'builds')
                ->where('expo.remote.pagination.builds.page', 1)
                ->where('expo.remote.pagination.builds.per_page', 10)
                ->where('expo.remote.pagination.builds.previous_url', null)
                ->where('expo.remote.pagination.builds.next_url', null)
                ->has('expo.remote.builds', 1)
                ->has('expo.remote.submissions', 1));

        Http::assertSent(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return str_contains($query, 'AppManagerExpoProject')
                && data_get(
                    $request->data(),
                    'variables.includeCredentialSecrets',
                ) === false
                && data_get($request->data(), 'variables.buildLimit') === 11
                && data_get($request->data(), 'variables.buildOffset') === 0
                && data_get($request->data(), 'variables.submissionLimit') === 11
                && data_get($request->data(), 'variables.submissionOffset') === 0;
        });
    }

    public function test_expo_activity_has_independent_build_and_submission_pagination(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
        ]);

        $remote = $this->remoteProject();
        $build = $remote['builds'][0];
        $submission = $remote['submissions'][0];
        $remote['builds'] = array_map(
            fn (int $index) => [...$build, 'id' => "build-{$index}"],
            range(1, 11),
        );
        $remote['submissions'] = array_map(
            fn (int $index) => [...$submission, 'id' => "submission-{$index}"],
            range(1, 11),
        );
        Http::fake([
            'https://expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $remote]],
            ]),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.apps.expo.index', [
                $team,
                $app,
                'tab' => 'submissions',
                'build_page' => 2,
                'submission_page' => 3,
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expo.remote.activity.active', 'submissions')
                ->has('expo.remote.builds', 10)
                ->has('expo.remote.submissions', 10)
                ->where('expo.remote.pagination.builds.page', 2)
                ->where('expo.remote.pagination.builds.previous_url', route(
                    'accounts.apps.expo.index',
                    [
                        $team,
                        $app,
                        'tab' => 'builds',
                        'build_page' => 1,
                        'submission_page' => 3,
                    ],
                ))
                ->where('expo.remote.pagination.builds.next_url', route(
                    'accounts.apps.expo.index',
                    [
                        $team,
                        $app,
                        'tab' => 'builds',
                        'build_page' => 3,
                        'submission_page' => 3,
                    ],
                ))
                ->where('expo.remote.pagination.submissions.page', 3)
                ->where('expo.remote.pagination.submissions.previous_url', route(
                    'accounts.apps.expo.index',
                    [
                        $team,
                        $app,
                        'tab' => 'submissions',
                        'build_page' => 2,
                        'submission_page' => 2,
                    ],
                ))
                ->where('expo.remote.pagination.submissions.next_url', route(
                    'accounts.apps.expo.index',
                    [
                        $team,
                        $app,
                        'tab' => 'submissions',
                        'build_page' => 2,
                        'submission_page' => 4,
                    ],
                )));

        Http::assertSent(fn (HttpRequest $request) => data_get(
            $request->data(),
            'variables.buildLimit',
        ) === 11
            && data_get($request->data(), 'variables.buildOffset') === 10
            && data_get($request->data(), 'variables.submissionLimit') === 11
            && data_get($request->data(), 'variables.submissionOffset') === 20);
    }

    public function test_finished_build_can_be_submitted_to_google_play(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
        ]);
        Http::fake(function (HttpRequest $request) {
            if (str_contains(
                (string) data_get($request->data(), 'query', ''),
                'AppManagerCreateExpoSubmission',
            )) {
                return Http::response(['data' => ['submission' => ['createAndroidSubmission' => [
                    'submission' => [
                        'id' => 'submission-new',
                        'platform' => 'ANDROID',
                        'status' => 'IN_QUEUE',
                        'createdAt' => now()->toIso8601String(),
                    ],
                ]]]]);
            }

            return Http::response(['data' => ['app' => ['byId' => $this->remoteProject()]]]);
        });

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.submissions.store', [$team, $app]), [
                'build_id' => 'build-1',
                'platform' => 'ANDROID',
                'application_identifier' => 'com.example.atlas',
                'track' => 'INTERNAL',
                'release_status' => 'COMPLETED',
                'google_service_account_key_id' => 'gsa-1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Expo submission started.');

        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains(
                (string) data_get($request->data(), 'query', ''),
                'AppManagerCreateExpoSubmission',
            )) {
                return false;
            }

            return data_get($request->data(), 'variables.input') === [
                'appId' => 'project-1',
                'submittedBuildId' => 'build-1',
                'config' => [
                    'applicationIdentifier' => 'com.example.atlas',
                    'track' => 'INTERNAL',
                    'releaseStatus' => 'COMPLETED',
                    'googleServiceAccountKeyId' => 'gsa-1',
                ],
            ];
        });
    }

    public function test_android_keystore_can_be_pushed_from_local_encrypted_credential(): void
    {
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Android',
        ]);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
        ]);
        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'name' => 'Production keystore',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);
        $identifier->credentials()->attach($credential);
        $credential->addMedia(UploadedFile::fake()->createWithContent(
            'release.jks',
            'fake-keystore-bytes',
        ))->toMediaCollection('file');

        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerCreateAndroidKeystore') => Http::response([
                    'data' => ['androidKeystore' => ['createAndroidKeystore' => [
                        'id' => 'keystore-1',
                        'keyAlias' => 'release',
                        'sha256CertificateFingerprint' => 'AA:BB',
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        ...$this->remoteProject(),
                        'androidAppCredentials' => [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateAndroidAppCredentials') => Http::response([
                    'data' => ['androidAppCredentials' => ['createAndroidAppCredentials' => [
                        'id' => 'android-app-credentials-1',
                    ]]],
                ]),
                default => Http::response([
                    'data' => ['androidAppBuildCredentials' => ['createAndroidAppBuildCredentials' => [
                        'id' => 'android-build-credentials-1',
                        'name' => 'Production keystore',
                        'isDefault' => true,
                    ]]],
                ]),
            };
        });

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.credentials.store', [
                $team,
                $app,
                $identifier,
                $credential,
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Credential pushed to Expo.');

        $link = ExpoCredentialLink::firstOrFail();
        $this->assertSame($project->id, $link->expo_project_id);
        $this->assertSame('AndroidKeystore', $link->remote_resource_type);
        $this->assertSame('keystore-1', $link->remote_resource_id);
        $this->assertSame(
            'android-build-credentials-1',
            $link->metadata['android_app_build_credentials_id'],
        );
        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.credentials.store', [
                $team,
                $app,
                $identifier,
                $credential,
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Credential synced to Expo.');
        $this->assertDatabaseCount('expo_credential_links', 1);

        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'AppManagerCreateAndroidKeystore',
        ) && data_get(
            $request->data(),
            'variables.input.base64EncodedKeystore',
        ) === base64_encode('fake-keystore-bytes'));
    }

    public function test_creating_an_expo_project_provisions_identifiers_and_fcm_v1_credentials(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Atlas Mobile',
            'slug' => 'atlas',
        ]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
            'name' => 'Android',
        ]);
        $credentialJson = json_encode([
            'type' => 'service_account',
            'project_id' => 'atlas-firebase',
            'client_email' => 'firebase@atlas.test',
            'private_key' => 'test-private-key',
        ], JSON_THROW_ON_ERROR);
        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::FirebaseConfig,
            'name' => 'Firebase HTTP v1',
        ]);
        $identifier->credentials()->attach($credential);
        $credential->addMedia(UploadedFile::fake()->createWithContent(
            'firebase-http-v1.json',
            $credentialJson,
        ))->toMediaCollection('file');
        $androidCredentialsCreated = false;

        Http::fake(function (HttpRequest $request) use (&$androidCredentialsCreated) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerCreateExpoProject') => Http::response([
                    'data' => ['app' => ['createApp' => [
                        'id' => 'project-new',
                        'name' => 'Atlas Mobile',
                        'slug' => 'atlas',
                        'fullName' => '@acme/atlas',
                        'description' => null,
                        'profileImageUrl' => null,
                        'updated' => now()->toIso8601String(),
                        'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        ...$this->remoteProject(),
                        'androidAppCredentials' => $androidCredentialsCreated ? [[
                            'id' => 'android-app-credentials-1',
                            'applicationIdentifier' => 'com.example.atlas',
                        ]] : [],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateAndroidAppCredentials') => (function () use (&$androidCredentialsCreated) {
                    $androidCredentialsCreated = true;

                    return Http::response([
                        'data' => ['androidAppCredentials' => ['createAndroidAppCredentials' => [
                            'id' => 'android-app-credentials-1',
                        ]]],
                    ]);
                })(),
                str_contains($query, 'AppManagerCreateFcmV1Credential') => Http::response([
                    'data' => ['androidAppCredentials' => ['createFcmV1Credential' => [
                        'id' => 'android-app-credentials-1',
                        'googleServiceAccountKeyForFcmV1' => [
                            'id' => 'fcm-key-1',
                            'clientEmail' => 'firebase@atlas.test',
                            'projectIdentifier' => 'atlas-firebase',
                        ],
                    ]]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected Expo request.']]]),
            };
        });

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.setup', [$team, $app]), [
                'integration_id' => $integration->id,
                'mode' => 'create',
                'sync_metadata' => false,
            ])
            ->assertRedirect(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSessionHas(
                'status',
                'Application set up on Expo with 1 identifiers and 1 credentials.',
            );

        $link = ExpoCredentialLink::firstOrFail();
        $this->assertSame('GoogleServiceAccountKey', $link->remote_resource_type);
        $this->assertSame('fcm-key-1', $link->remote_resource_id);
        $this->assertSame('fcm_v1', $link->metadata['purpose']);
        Http::assertSent(fn (HttpRequest $request) => str_contains(
            (string) data_get($request->data(), 'query', ''),
            'createFcmV1Credential',
        ) && data_get($request->data(), 'variables.credential') === $credentialJson);
    }

    public function test_failed_apple_build_credential_attachment_is_shown_and_not_marked_complete(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        [$integration] = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'iOS',
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $certificate = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
            'name' => 'Distribution certificate',
            'payload' => ['password' => 'certificate-secret'],
        ]);
        $provisioningProfile = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::ProvisioningProfile,
            'name' => 'Provisioning profile',
        ]);
        $profile->credentials()->attach([$certificate->id, $provisioningProfile->id]);
        $provisioningProfile->addMedia(UploadedFile::fake()->createWithContent(
            'profile.mobileprovision',
            'provisioning-profile-bytes',
        ))->toMediaCollection('file');
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);
        ExpoCredentialLink::create([
            'integration_id' => $integration->id,
            'expo_project_id' => $project->id,
            'credential_id' => $certificate->id,
            'app_identifier_id' => $identifier->id,
            'remote_resource_type' => 'AppleDistributionCertificate',
            'remote_resource_id' => 'certificate-1',
            'metadata' => ['profile_type' => ProfileType::AppStore->value],
        ]);

        Http::fake(function (HttpRequest $request) {
            $query = (string) data_get($request->data(), 'query', '');

            return match (true) {
                str_contains($query, 'AppManagerAppleAppIdentifier') => Http::response([
                    'data' => ['account' => ['byId' => ['appleAppIdentifiers' => [[
                        'id' => 'apple-app-id-1',
                        'bundleIdentifier' => 'com.example.atlas',
                    ]]]]],
                ]),
                str_contains($query, 'AppManagerCreateAppleProvisioningProfile') => Http::response([
                    'data' => ['appleProvisioningProfile' => ['createAppleProvisioningProfile' => [
                        'id' => 'profile-1',
                        'appleUUID' => 'profile-uuid',
                        'expiration' => now()->addYear()->toIso8601String(),
                        'status' => 'ACTIVE',
                    ]]],
                ]),
                str_contains($query, 'AppManagerExpoProject') => Http::response([
                    'data' => ['app' => ['byId' => [
                        ...$this->remoteProject(),
                        'iosAppCredentials' => [[
                            'id' => 'ios-app-credentials-1',
                            'appleAppIdentifier' => [
                                'id' => 'apple-app-id-1',
                                'bundleIdentifier' => 'com.example.atlas',
                            ],
                        ]],
                    ]]],
                ]),
                str_contains($query, 'AppManagerCreateIosBuildCredentials') => Http::response([
                    'errors' => [[
                        'message' => 'The certificate and provisioning profile do not match.',
                    ]],
                ]),
                default => Http::response(['errors' => [['message' => 'Unexpected Expo request.']]]),
            };
        });

        $this->actingAs($owner)
            ->post(route('accounts.apps.expo.credentials.store', [
                $team,
                $app,
                $identifier,
                $provisioningProfile,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'expo' => 'The certificate and provisioning profile do not match.',
            ]);

        $this->assertDatabaseCount('expo_credential_links', 1);
        $this->assertDatabaseMissing('expo_credential_links', [
            'credential_id' => $provisioningProfile->id,
        ]);

        $project->load(['integration', 'credentialLinks']);
        $certificateState = CredentialPresenter::present(
            $team,
            $certificate,
            $identifier,
            $profile,
            $project,
        );
        $profileState = CredentialPresenter::present(
            $team,
            $provisioningProfile,
            $identifier,
            $profile,
            $project,
        );
        $this->assertTrue($certificateState['expo']['pushed']);
        $this->assertFalse($certificateState['expo']['complete']);
        $this->assertFalse($profileState['expo']['pushed']);
        $this->assertFalse($profileState['expo']['complete']);
    }

    public function test_expo_integration_can_be_disabled_by_the_operator(): void
    {
        [$owner, $team] = $this->member();
        config()->set('integrations.expo.enabled', false);
        Http::preventStrayRequests();

        $this->actingAs($owner)
            ->post(route('accounts.integrations.expo.store', $team), [
                'access_token' => 'expo-secret-token',
            ])
            ->assertNotFound();
    }

    /** @return array{User, Team} */
    private function member(TeamRole $role = TeamRole::Owner): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => $role->value]);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    /** @return array{Integration, IntegrationOAuthCredential} */
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
        $credential = IntegrationOAuthCredential::create([
            'integration_id' => $integration->id,
            'access_token' => 'expo-secret-token',
            'token_type' => 'Bearer',
        ]);

        return [$integration, $credential];
    }

    private function assertCredentialFile(
        Credential $credential,
        string $fileName,
        string $contents,
    ): void {
        $media = $credential->getFirstMedia('file');
        $this->assertNotNull($media);
        $this->assertSame($fileName, $media->file_name);
        $this->assertSame(
            $contents,
            CredentialFileStorage::contents($media),
        );
        $this->assertNotSame(
            $contents,
            Storage::disk($media->disk)->get($media->getPathRelativeToRoot()),
        );
    }

    /** @return array<string, mixed> */
    private function remoteProject(): array
    {
        return [
            'id' => 'project-1',
            'name' => 'Atlas',
            'slug' => 'atlas',
            'fullName' => '@acme/atlas',
            'description' => 'Expo project',
            'profileImageUrl' => null,
            'updated' => now()->toIso8601String(),
            'buildProfiles' => ['production'],
            'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
            'androidAppCredentials' => [[
                'id' => 'android-creds-1',
                'applicationIdentifier' => 'com.example.atlas',
                'googleServiceAccountKeyForFcmV1' => null,
                'googleServiceAccountKeyForSubmissions' => [
                    'id' => 'gsa-1',
                    'clientEmail' => 'release@acme.iam.gserviceaccount.com',
                    'projectIdentifier' => 'acme',
                    'updatedAt' => now()->toIso8601String(),
                ],
                'androidAppBuildCredentialsList' => [],
            ]],
            'iosAppCredentials' => [],
            'builds' => [[
                'id' => 'build-1',
                'platform' => 'ANDROID',
                'status' => 'FINISHED',
                'buildProfile' => 'production',
                'distribution' => 'STORE',
                'appIdentifier' => 'com.example.atlas',
                'appVersion' => '1.0.0',
                'appBuildVersion' => '1',
                'gitRef' => 'main',
                'gitCommitHash' => 'abc123',
                'message' => null,
                'createdAt' => now()->subMinute()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
                'completedAt' => now()->toIso8601String(),
                'canRetry' => true,
                'error' => null,
                'artifacts' => [
                    'applicationArchiveUrl' => 'https://artifacts.test/app.aab',
                    'buildUrl' => 'https://artifacts.test/build.zip',
                ],
            ]],
            'submissions' => [[
                'id' => 'submission-1',
                'platform' => 'ANDROID',
                'status' => 'FINISHED',
                'archiveUrl' => 'https://artifacts.test/app.aab',
                'canRetry' => true,
                'createdAt' => now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
                'completedAt' => now()->toIso8601String(),
                'error' => null,
                'submittedBuild' => ['id' => 'build-1'],
                'androidConfig' => [
                    'applicationIdentifier' => 'com.example.atlas',
                    'track' => 'INTERNAL',
                ],
                'iosConfig' => null,
            ]],
        ];
    }
}
