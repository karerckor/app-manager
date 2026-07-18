<?php

namespace Tests\Feature\Integrations;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        config()->set([
            'integrations.enabled' => true,
            'integrations.github.enabled' => true,
            'integrations.github.app_id' => '12345',
            'integrations.github.client_id' => 'Iv1.test',
            'integrations.github.client_secret' => 'client-secret',
            'integrations.github.slug' => 'app-manager-test',
            'integrations.github.private_key' => $privateKey,
            'integrations.github.web_url' => 'https://github.test',
            'integrations.github.api_url' => 'https://api.github.test',
            'integrations.github.api_version' => '2026-03-10',
        ]);
    }

    public function test_owner_connects_only_an_installation_accessible_to_their_github_user(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://github.test/login/oauth/access_token' => Http::response(['access_token' => 'user-token']),
            'https://api.github.test/user/installations*' => Http::response([
                'total_count' => 1,
                'installations' => [['id' => 42]],
            ]),
            'https://api.github.test/app/installations/42' => Http::response($this->installation()),
        ]);

        $connect = $this->actingAs($owner)->get(route('accounts.integrations.github.connect', $team));
        $connect->assertRedirectContains('https://github.test/apps/app-manager-test/installations/new?');
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('integrations.github.callback', [
            'state' => $query['state'],
            'code' => 'github-code',
            'installation_id' => 42,
        ]))->assertRedirect(route('accounts.integrations.index', $team))
            ->assertSessionHas('status', 'GitHub connected.');

        $this->assertDatabaseHas('integrations', [
            'team_id' => $team->id,
            'provider' => 'github',
            'external_id' => '42',
            'external_account_name' => 'octo-org',
            'status' => 'active',
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/user/installations?per_page=100'
            && $request->hasHeader('Authorization', 'Bearer user-token'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/app/installations/42'
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer eyJ'));
    }

    public function test_forged_installation_id_is_rejected_and_state_is_single_use(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://github.test/login/oauth/access_token' => Http::response(['access_token' => 'user-token']),
            'https://api.github.test/user/installations*' => Http::response([
                'total_count' => 1,
                'installations' => [['id' => 42]],
            ]),
        ]);
        $connect = $this->actingAs($owner)->get(route('accounts.integrations.github.connect', $team));
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = route('integrations.github.callback', [
            'state' => $query['state'],
            'code' => 'github-code',
            'installation_id' => 99,
        ]);

        $this->get($callback)->assertForbidden();
        $this->get($callback)->assertStatus(419);
        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_admin_can_start_connection_but_lower_roles_cannot(): void
    {
        [$admin, $team] = $this->member(TeamRole::Admin);
        $contributor = User::factory()->create();
        $viewer = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $team->addUser($viewer, TeamRole::Viewer);

        $this->actingAs($admin)
            ->get(route('accounts.integrations.github.connect', $team))
            ->assertRedirectContains('https://github.test/apps/app-manager-test/installations/new?');
        $this->actingAs($contributor)
            ->get(route('accounts.integrations.github.connect', $team))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('accounts.integrations.github.connect', $team))
            ->assertForbidden();
    }

    public function test_disabled_feature_returns_not_found(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        config()->set('integrations.github.enabled', false);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.github.connect', $team))
            ->assertNotFound();
    }

    public function test_connection_fails_before_installation_when_client_secret_is_missing(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        config()->set('integrations.github.client_secret', '');

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub App client ID and client secret must be configured.');

        $this->actingAs($owner)
            ->get(route('accounts.integrations.github.connect', $team));
    }

    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }

    private function installation(): array
    {
        return [
            'id' => 42,
            'account' => ['id' => 777, 'login' => 'octo-org'],
            'target_type' => 'Organization',
            'repository_selection' => 'selected',
            'permissions' => ['actions' => 'write', 'contents' => 'read'],
        ];
    }
}
