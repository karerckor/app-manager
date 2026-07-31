<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GitLabConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'integrations.enabled' => true,
            'integrations.gitlab.enabled' => true,
            'integrations.gitlab.self_hosted_enabled' => true,
            'integrations.gitlab.client_id' => 'gitlab-client',
            'integrations.gitlab.client_secret' => 'gitlab-secret',
            'integrations.gitlab.web_url' => 'https://gitlab.test',
            'integrations.gitlab.api_url' => 'https://gitlab.test/api/v4',
            'integrations.gitlab.scopes' => ['api', 'openid'],
        ]);
    }

    public function test_owner_connects_gitlab_and_tokens_are_stored_outside_audited_settings(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://gitlab.test/oauth/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 7200,
                'created_at' => now()->timestamp,
            ]),
            'https://gitlab.test/api/v4/user' => Http::response([
                'id' => 42,
                'username' => 'octo',
                'name' => 'Octo Cat',
                'web_url' => 'https://gitlab.test/octo',
            ]),
        ]);

        $connect = $this->actingAs($owner)->get(route('accounts.integrations.gitlab.connect', $team));
        $connect->assertRedirectContains('https://gitlab.test/oauth/authorize?');
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('integrations.gitlab.callback', [
            'state' => $query['state'],
            'code' => 'gitlab-code',
        ]))->assertRedirect(route('accounts.integrations.index', $team))
            ->assertSessionHas('status', 'GitLab connected.');

        $integration = Integration::firstOrFail();
        $credential = IntegrationOAuthCredential::firstOrFail();
        $this->assertSame('gitlab', $integration->provider->value);
        $this->assertSame('42', $integration->external_id);
        $this->assertSame('octo', $integration->external_account_name);
        $this->assertArrayNotHasKey('access_token', $integration->settings);
        $this->assertSame('access-token', $credential->access_token);
        $this->assertNotSame('access-token', $credential->getRawOriginal('access_token'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gitlab.test/oauth/token'
            && $request['redirect_uri'] === route('integrations.gitlab.callback'));
    }

    public function test_self_hosted_gitlab_can_be_connected_alongside_the_shared_instance(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitLab,
            'name' => 'GitLab shared-user',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
            'external_account_id' => '42',
            'external_account_name' => 'shared-user',
        ]);
        Http::fake([
            'https://gitlab.company.test/platform/api/v4/user' => Http::response([
                'id' => 42,
                'username' => 'company-user',
                'name' => 'Company User',
                'web_url' => 'https://gitlab.company.test/platform/company-user',
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.gitlab.self-hosted.store', $team), [
                'instance_url' => 'https://GITLAB.company.test:443/platform/',
                'access_token' => 'glpat-company-token',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Self-hosted GitLab connected.');

        $this->assertDatabaseCount('integrations', 2);
        $selfHosted = Integration::query()
            ->where('external_account_name', 'company-user')
            ->firstOrFail();
        $credential = $selfHosted->oauthCredential()->firstOrFail();

        $this->assertSame('42', $selfHosted->external_account_id);
        $this->assertNotSame('42', $selfHosted->external_id);
        $this->assertStringStartsWith('self-hosted:', $selfHosted->external_id);
        $this->assertSame('self_hosted', $selfHosted->settings['connection_type']);
        $this->assertSame(
            'https://gitlab.company.test/platform',
            $selfHosted->settings['gitlab_web_url'],
        );
        $this->assertSame(
            'https://gitlab.company.test/platform/api/v4',
            $selfHosted->settings['gitlab_api_url'],
        );
        $this->assertArrayNotHasKey('access_token', $selfHosted->settings);
        $this->assertSame('Private-Token', $credential->token_type);
        $this->assertSame('glpat-company-token', $credential->access_token);
        $this->assertNotSame(
            'glpat-company-token',
            $credential->getRawOriginal('access_token'),
        );
        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://gitlab.company.test/platform/api/v4/user'
            && $request->hasHeader('PRIVATE-TOKEN', 'glpat-company-token'));

        $this->get(route('accounts.integrations.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ciCdProviders.1.connected_accounts_count', 1)
                ->where('selfHostedGitLab.enabled', true)
                ->where('selfHostedGitLab.connected_accounts_count', 1)
                ->where(
                    'selfHostedGitLab.store_url',
                    route('accounts.integrations.gitlab.self-hosted.store', $team),
                ));
    }

    public function test_self_hosted_gitlab_sync_keeps_its_instance_and_identity(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://gitlab.company.test/api/v4/user' => Http::sequence()
                ->push([
                    'id' => 42,
                    'username' => 'company-user',
                    'name' => 'Company User',
                ])
                ->push([
                    'id' => 42,
                    'username' => 'renamed-user',
                    'name' => 'Renamed User',
                ]),
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.gitlab.self-hosted.store', $team), [
                'instance_url' => 'https://gitlab.company.test',
                'access_token' => 'glpat-company-token',
            ])
            ->assertRedirect();
        $integration = Integration::firstOrFail();
        $externalId = $integration->external_id;

        $this->post(route('accounts.integrations.gitlab.sync', [$team, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'GitLab connection synced.');

        $this->assertDatabaseCount('integrations', 1);
        $this->assertSame($externalId, $integration->refresh()->external_id);
        $this->assertSame('renamed-user', $integration->external_account_name);
        $this->assertSame(
            'https://gitlab.company.test/api/v4',
            $integration->settings['gitlab_api_url'],
        );
    }

    public function test_self_hosted_account_does_not_force_login_for_the_first_shared_account(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitLab,
            'name' => 'GitLab company-user · gitlab.company.test',
            'status' => IntegrationStatus::Active,
            'external_id' => 'self-hosted:instance:42',
            'external_account_id' => '42',
            'external_account_name' => 'company-user',
            'settings' => [
                'connection_type' => 'self_hosted',
                'gitlab_web_url' => 'https://gitlab.company.test',
                'gitlab_api_url' => 'https://gitlab.company.test/api/v4',
            ],
        ]);

        $connect = $this->actingAs($owner)
            ->get(route('accounts.integrations.gitlab.connect', $team));
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('prompt', $query);
    }

    public function test_rejected_self_hosted_token_is_not_persisted_or_flashed(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://gitlab.company.test/api/v4/user' => Http::response([], 401),
        ]);

        $this->actingAs($owner)
            ->from(route('accounts.integrations.index', $team))
            ->post(route('accounts.integrations.gitlab.self-hosted.store', $team), [
                'instance_url' => 'https://gitlab.company.test',
                'access_token' => 'glpat-rejected-token',
            ])
            ->assertRedirect(route('accounts.integrations.index', $team))
            ->assertSessionHasErrors('access_token')
            ->assertSessionMissing('_old_input.access_token');

        $this->assertDatabaseCount('integrations', 0);
        $this->assertDatabaseCount('integration_oauth_credentials', 0);
    }

    public function test_state_is_provider_bound_and_single_use(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $connect = $this->actingAs($owner)->get(route('accounts.integrations.gitlab.connect', $team));
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = route('integrations.github.callback', [
            'state' => $query['state'],
            'code' => 'forged-code',
        ]);

        $this->get($callback)->assertStatus(419);
        $this->get(route('integrations.gitlab.callback', [
            'state' => $query['state'],
            'code' => 'gitlab-code',
        ]))->assertStatus(419);
        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_repeated_connection_prompts_for_login_and_stores_a_second_account(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $firstIntegration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitLab,
            'name' => 'GitLab first-user',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
            'external_account_id' => '42',
            'external_account_name' => 'first-user',
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $firstIntegration->id,
            'access_token' => 'first-access-token',
            'refresh_token' => 'first-refresh-token',
        ]);
        Http::fake([
            'https://gitlab.test/oauth/token' => Http::response([
                'access_token' => 'second-access-token',
                'refresh_token' => 'second-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 7200,
                'created_at' => now()->timestamp,
            ]),
            'https://gitlab.test/api/v4/user' => Http::response([
                'id' => 84,
                'username' => 'second-user',
                'name' => 'Second User',
                'web_url' => 'https://gitlab.test/second-user',
            ]),
        ]);

        $connect = $this->actingAs($owner)->get(route('accounts.integrations.gitlab.connect', $team));
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('login', $query['prompt'] ?? null);

        $this->get(route('integrations.gitlab.callback', [
            'state' => $query['state'],
            'code' => 'gitlab-code',
        ]))->assertRedirect(route('accounts.integrations.index', $team))
            ->assertSessionHas('status', 'GitLab connected.');

        $this->assertDatabaseCount('integrations', 2);
        $this->assertDatabaseCount('integration_oauth_credentials', 2);
        $this->assertDatabaseHas('integrations', [
            'team_id' => $team->id,
            'provider' => 'gitlab',
            'external_id' => '42',
            'external_account_name' => 'first-user',
        ]);
        $this->assertDatabaseHas('integrations', [
            'team_id' => $team->id,
            'provider' => 'gitlab',
            'external_id' => '84',
            'external_account_name' => 'second-user',
        ]);
        $this->assertSame(
            'second-access-token',
            Integration::query()
                ->where('provider', IntegrationProvider::GitLab)
                ->where('external_id', '84')
                ->firstOrFail()
                ->oauthCredential
                ->access_token,
        );
    }

    public function test_disabled_gitlab_connection_is_not_exposed(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        config()->set('integrations.gitlab.enabled', false);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.gitlab.connect', $team))
            ->assertNotFound();
    }

    public function test_disabled_self_hosted_gitlab_connection_is_not_exposed(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        config()->set('integrations.gitlab.self_hosted_enabled', false);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.gitlab.self-hosted.store', $team), [
                'instance_url' => 'https://gitlab.company.test',
                'access_token' => 'glpat-company-token',
            ])
            ->assertNotFound();
    }

    public function test_contributor_cannot_connect_self_hosted_gitlab(): void
    {
        [$contributor, $team] = $this->member(TeamRole::Contributor);
        Http::preventStrayRequests();

        $this->actingAs($contributor)
            ->post(route('accounts.integrations.gitlab.self-hosted.store', $team), [
                'instance_url' => 'https://gitlab.company.test',
                'access_token' => 'glpat-company-token',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_connecting_another_gitlab_account_requires_the_openid_scope(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Integration::factory()->create([
            'team_id' => $team->id,
            'provider' => IntegrationProvider::GitLab,
            'external_id' => '42',
        ]);
        config()->set('integrations.gitlab.scopes', ['api']);

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'GitLab OAuth scope "openid" must be configured to connect another account.',
        );

        $this->actingAs($owner)
            ->get(route('accounts.integrations.gitlab.connect', $team));
    }

    public function test_disconnect_removes_local_oauth_credentials_and_preserves_integration_history(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitLab,
            'name' => 'GitLab octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $integration->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.gitlab.disconnect', [$team, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'GitLab disconnected. Historical builds were preserved.');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'status' => IntegrationStatus::Disconnected->value,
        ]);
        $this->assertDatabaseCount('integration_oauth_credentials', 0);
    }

    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }
}
