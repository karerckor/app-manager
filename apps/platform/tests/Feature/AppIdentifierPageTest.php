<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppIdentifierPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_index_lists_app_identifiers(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
            'version' => '1.0.0',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Index')
                ->where('app.id', $app->id)
                ->has('identifiers', 1)
                ->where('identifiers.0.identifier', 'com.example.atlas')
                ->where('identifiers.0.sync_status', 'database')
                ->where('identifiers.0.sources.database', true)
                ->where('identifiers.0.sources.expo', false)
                ->where('expoIdentifiers.linked', false)
                ->where('identifiers.0.show_url', route('accounts.apps.identifiers.show', [$team, $app, $identifier])));
    }

    public function test_index_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.apps.identifiers.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }

    public function test_show_returns_identifier_with_credentials(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Show')
                ->where('identifier.id', $identifier->id)
                ->where('identifier.identifier', 'com.example.atlas')
                ->has('identifier.services')
                ->has('identifier.build_targets')
                ->has('credentialTypes'));
    }

    public function test_web_identifier_can_be_created_from_a_domain_and_has_no_credentials(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(route('accounts.apps.identifiers.store', [$team, $app]), [
            'platform' => Platform::Web->value,
            'identifier' => '  Portal.Example.COM  ',
            'name' => 'Customer portal',
            'version' => '2026.7',
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $identifier = $app->appIdentifiers()->firstOrFail();
        $this->assertSame(Platform::Web, $identifier->platform);
        $this->assertSame('portal.example.com', $identifier->identifier);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifier.platform', Platform::Web->value)
                ->where('identifier.supports_credentials', false)
                ->where('identifier.attach_credential_url', null)
                ->where('identifier.credentials_count', 0)
                ->has('identifier.services', 0)
                ->has('identifier.build_targets', 0)
                ->has('credentialTypes', 0)
                ->has('availableCredentials', 0));
    }

    public function test_web_identifier_requires_a_domain_without_a_url_or_port(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        foreach ([
            'https://example.com',
            'example.com/path',
            'example.com:443',
            '-portal.example.com',
            'portal_example.com',
            'localhost',
        ] as $invalidDomain) {
            $this->actingAs($user)->post(route('accounts.apps.identifiers.store', [$team, $app]), [
                'platform' => Platform::Web->value,
                'identifier' => $invalidDomain,
            ])
                ->assertRedirect()
                ->assertSessionHasErrors('identifier');
        }

        $this->assertDatabaseCount('app_identifiers', 0);
    }

    public function test_credentials_cannot_be_attached_to_a_web_identifier(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Web,
            'identifier' => 'portal.example.com',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AppleApiKey,
        ]);

        $this->actingAs($user)->post(
            route('accounts.apps.identifiers.credentials.attach', [$team, $app, $identifier]),
            ['credential_id' => $credential->id],
        )
            ->assertRedirect()
            ->assertSessionHasErrors('credential_id');

        $this->assertFalse($identifier->credentials()->exists());
    }

    public function test_identifier_with_credentials_cannot_be_changed_to_web(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AppleApiKey,
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user)->patch(
            route('accounts.apps.identifiers.update', [$team, $app, $identifier]),
            [
                'platform' => Platform::Web->value,
                'identifier' => 'atlas.example.com',
                'name' => null,
                'version' => null,
                'version_code' => null,
            ],
        )
            ->assertRedirect()
            ->assertSessionHasErrors('platform');

        $this->assertSame(Platform::Ios, $identifier->fresh()->platform);
        $this->assertTrue($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_show_exposes_a_protected_reveal_action_for_configured_credential_fields(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AppleApiKey,
            'payload' => [
                'key_id' => 'ABCDEFGHIJ',
                'issuer_id' => '025c7398-0f0e-487d-9a22-4e154b3427ea',
            ],
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifier.services.0.reveal_fields_count', 2)
                ->where(
                    'identifier.services.0.reveal_url',
                    route('accounts.credentials.reveal', [$team, $credential]),
                ));

        $this->postJson(
            route('accounts.credentials.reveal', [$team, $credential]),
            ['password' => 'password', 'scope' => 'all'],
        )
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'secrets' => [
                    [
                        'name' => 'key_id',
                        'label' => 'Key ID',
                        'value' => 'ABCDEFGHIJ',
                    ],
                    [
                        'name' => 'issuer_id',
                        'label' => 'Issuer ID',
                        'value' => '025c7398-0f0e-487d-9a22-4e154b3427ea',
                    ],
                ],
            ]);
    }

    public function test_show_404_when_identifier_belongs_to_another_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $otherApp->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.other',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertNotFound();
    }
}
