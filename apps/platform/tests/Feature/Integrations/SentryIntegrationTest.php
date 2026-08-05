<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\SentryEnvelopeStatus;
use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use App\Enums\TeamRole;
use App\Jobs\Sentry\ForwardSentryEnvelope;
use App\Models\AppIdentifier;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SentryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.enabled' => true,
            'integrations.sentry.enabled' => true,
            'webhooks.allow_private_addresses' => true,
        ]);
    }

    public function test_sentry_page_exposes_routed_dsns_and_recent_telemetry(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$app, $identifier] = $this->appTarget($team, 'storefront');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Event, SentryEventSource::Auto);
        $route = $this->route($dsn, $app, $identifier);
        $envelope = $this->storedEnvelope($dsn, $route);
        SentryEvent::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'sentry_envelope_id' => $envelope->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'event_id' => str_repeat('e', 32),
            'item_type' => 'event',
            'level' => 'error',
            'title' => 'TypeError: cart is missing',
            'occurred_at' => now(),
            'payload' => ['message' => 'cart is missing'],
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.sentry.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->where('activeTab', 'sentry')
                ->where('sentryEnabled', true)
                ->has('sentryDsns', 1)
                ->where('sentryDsns.0.name', 'Production telemetry')
                ->where('sentryDsns.0.routing_method', 'event')
                ->where('sentryDsns.0.routes.0.app_slug', $app->slug)
                ->where('sentryDsns.0.routes.0.identifier', $identifier->identifier)
                ->where('sentryDsns.0.routes.0.dsn', $dsn->dsn($route->public_key))
                ->has('sentryEvents', 1)
                ->where('sentryEvents.0.app_name', $app->name)
                ->where('sentryEvents.0.title', 'TypeError: cart is missing'));
    }

    public function test_owner_can_create_update_disable_and_remove_a_routed_dsn(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$app, $identifier] = $this->appTarget($team, 'consumer');

        $this->actingAs($owner)
            ->post(route('accounts.integrations.sentry.store', $team), [
                'name' => 'Mobile errors',
                'routing_method' => 'query',
                'event_source' => null,
                'app_key' => 'app_slug',
                'identifier_key' => 'package_id',
                'routes' => [[
                    'mobile_app_id' => $app->id,
                    'app_identifier_id' => $identifier->id,
                ]],
                'forward_enabled' => true,
                'upstream_dsn' => 'https://upstream-public@sentry.example.test/42',
                'enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $dsn = SentryDsn::with('integration')->sole();
        $this->assertSame(IntegrationKind::Observability, $dsn->integration->kind);
        $this->assertSame(IntegrationProvider::Sentry, $dsn->integration->provider);
        $this->assertSame(SentryRoutingMethod::Query, $dsn->routing_method);
        $this->assertSame('https://upstream-public@sentry.example.test/42', $dsn->upstream_dsn);
        $this->assertStringNotContainsString('upstream-public', (string) $dsn->getRawOriginal('upstream_dsn'));
        $this->assertMatchesRegularExpression('/^https?:\/\/[a-f0-9]{32}@.+\/\d+$/', $dsn->dsn());
        $this->assertNotNull($dsn->routes()->sole()->public_key);

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.sentry.update', [$team, $dsn]), [
                'name' => 'Scoped errors',
                'routing_method' => 'event',
                'event_source' => 'tags',
                'app_key' => 'app_slug',
                'identifier_key' => '',
                'routes' => [[
                    'mobile_app_id' => $app->id,
                    'app_identifier_id' => null,
                ]],
                'forward_enabled' => true,
                'upstream_dsn' => '',
                'enabled' => false,
            ])
            ->assertRedirect();

        $dsn->refresh();
        $this->assertSame('Scoped errors', $dsn->integration->name);
        $this->assertSame(IntegrationStatus::Disabled, $dsn->integration->status);
        $this->assertSame(SentryEventSource::Tags, $dsn->event_source);
        $this->assertSame('https://upstream-public@sentry.example.test/42', $dsn->upstream_dsn);
        $this->assertNull($dsn->routes()->sole()->app_identifier_id);

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.sentry.destroy', [$team, $dsn]))
            ->assertRedirect();

        $this->assertSoftDeleted($dsn);
        $this->assertSame(
            IntegrationStatus::Disconnected,
            Integration::findOrFail($dsn->integration_id)->status,
        );
    }

    public function test_routed_dsn_rejects_cross_team_and_mismatched_identifier_targets(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [, $otherTeam] = $this->member(TeamRole::Owner);
        [$app] = $this->appTarget($team, 'first');
        [, $otherIdentifier] = $this->appTarget($otherTeam, 'other');

        $payload = [
            'name' => 'Invalid route',
            'routing_method' => 'event',
            'event_source' => 'auto',
            'app_key' => 'app.slug',
            'identifier_key' => 'app.identifier',
            'routes' => [[
                'mobile_app_id' => $app->id,
                'app_identifier_id' => $otherIdentifier->id,
            ]],
            'forward_enabled' => false,
            'upstream_dsn' => '',
            'enabled' => true,
        ];

        $this->actingAs($owner)
            ->post(route('accounts.integrations.sentry.store', $team), $payload)
            ->assertSessionHasErrors('routes.0.app_identifier_id');

        $payload['routes'][0]['mobile_app_id'] = $otherIdentifier->mobileApp->id;
        $this->actingAs($owner)
            ->post(route('accounts.integrations.sentry.store', $team), $payload)
            ->assertSessionHasErrors('routes.0.mobile_app_id');

        $this->assertDatabaseCount('sentry_dsns', 0);
    }

    public function test_query_routing_ingests_an_error_for_the_matching_app_and_queues_relay(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$firstApp, $firstIdentifier] = $this->appTarget($team, 'first');
        [$secondApp, $secondIdentifier] = $this->appTarget($team, 'second');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Query, upstream: 'https://upstream@sentry.example.test/42');
        $this->route($dsn, $firstApp, $firstIdentifier);
        $this->route($dsn, $secondApp, $secondIdentifier);
        $eventId = str_repeat('a', 32);
        $body = $this->envelope($dsn, [
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'javascript',
            'level' => 'error',
            'exception' => ['values' => [[
                'type' => 'TypeError',
                'value' => 'Checkout failed',
            ]]],
            'contexts' => ['trace' => ['trace_id' => str_repeat('b', 32)]],
        ]);
        $url = route('sentry.envelope.store', ['sentryProjectId' => $dsn->project_id])
            .'?'.http_build_query([
                'sentry_key' => $dsn->public_key,
                'app_slug' => $secondApp->slug,
                'app_identifier' => $secondIdentifier->identifier,
            ]);

        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/x-sentry-envelope',
        ], $body)
            ->assertSuccessful()
            ->assertJson(['id' => $eventId]);

        $event = SentryEvent::sole();
        $this->assertSame($secondApp->id, $event->mobile_app_id);
        $this->assertSame($secondIdentifier->id, $event->app_identifier_id);
        $this->assertSame('TypeError: Checkout failed', $event->title);
        $this->assertSame(str_repeat('b', 32), $event->trace_id);
        Queue::assertPushed(ForwardSentryEnvelope::class, fn ($job) => $job->envelopeId === $event->sentry_envelope_id);
    }

    public function test_context_routing_ingests_a_transaction_and_supports_platform_values(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$iosApp, $iosIdentifier] = $this->appTarget($team, 'ios');
        [$webApp, $webIdentifier] = $this->appTarget($team, 'web', Platform::Web, 'shop.example.com');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Event, SentryEventSource::Contexts);
        $this->route($dsn, $iosApp, $iosIdentifier);
        $this->route($dsn, $webApp, $webIdentifier);
        $body = $this->envelope($dsn, [
            'event_id' => str_repeat('c', 32),
            'type' => 'transaction',
            'transaction' => 'GET /checkout',
            'timestamp' => now()->timestamp,
            'contexts' => [
                'app' => ['slug' => $webApp->slug, 'identifier' => 'web'],
                'trace' => ['trace_id' => str_repeat('d', 32)],
            ],
        ], 'transaction');

        $this->call('POST', route('sentry.envelope.store', $dsn->project_id), [], [], [], [
            'CONTENT_TYPE' => 'application/x-sentry-envelope',
            'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$dsn->public_key}",
        ], $body)->assertSuccessful();

        $event = SentryEvent::sole();
        $this->assertSame('transaction', $event->item_type);
        $this->assertSame($webApp->id, $event->mobile_app_id);
        $this->assertSame('GET /checkout', $event->transaction);
    }

    public function test_routing_within_one_app_resolves_platform_or_web_domain(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'slug' => 'storefront',
        ]);
        $iosIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.storefront',
        ]);
        $androidIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.storefront',
        ]);
        $webIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Web,
            'identifier' => 'storefront.example.com',
        ]);
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Query);
        $this->route($dsn, $app, $iosIdentifier);
        $this->route($dsn, $app, $androidIdentifier);
        $this->route($dsn, $app, $webIdentifier);

        foreach ([
            ['ios', $iosIdentifier, str_repeat('1', 32)],
            ['storefront.example.com', $webIdentifier, str_repeat('2', 32)],
        ] as [$routingValue, $expectedIdentifier, $eventId]) {
            $body = $this->envelope($dsn, ['event_id' => $eventId, 'message' => "Target {$routingValue}"]);
            $url = route('sentry.envelope.store', $dsn->project_id).'?'.http_build_query([
                'sentry_key' => $dsn->public_key,
                'app_slug' => $app->slug,
                'app_identifier' => $routingValue,
            ]);

            $this->call('POST', $url, [], [], [], [], $body)->assertSuccessful();
            $this->assertSame(
                $expectedIdentifier->id,
                SentryEvent::query()->where('event_id', $eventId)->sole()->app_identifier_id,
            );
        }

        $ambiguousBody = $this->envelope($dsn, [
            'event_id' => str_repeat('3', 32),
            'message' => 'Ambiguous package',
        ]);
        $ambiguousUrl = route('sentry.envelope.store', $dsn->project_id).'?'.http_build_query([
            'sentry_key' => $dsn->public_key,
            'app_slug' => $app->slug,
            'app_identifier' => 'com.example.storefront',
        ]);

        $this->call('POST', $ambiguousUrl, [], [], [], [], $ambiguousBody)
            ->assertBadRequest()
            ->assertJsonPath('detail', 'The routing values do not identify exactly one configured application target.');
    }

    #[DataProvider('eventMetadataSources')]
    public function test_event_routing_supports_scope_metadata_sources(
        SentryEventSource $source,
        string $payloadRoot,
    ): void {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$firstApp, $firstIdentifier] = $this->appTarget($team, 'first');
        [$secondApp, $secondIdentifier] = $this->appTarget($team, 'second');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Event, $source);
        $this->route($dsn, $firstApp, $firstIdentifier);
        $this->route($dsn, $secondApp, $secondIdentifier);
        $body = $this->envelope($dsn, [
            'event_id' => str_repeat('7', 32),
            'message' => 'Scoped metadata',
            $payloadRoot => [
                'app.slug' => $secondApp->slug,
                'app.identifier' => $secondIdentifier->identifier,
            ],
        ]);

        $this->call('POST', route('sentry.envelope.store', $dsn->project_id), [], [], [], [
            'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$dsn->public_key}",
        ], $body)->assertSuccessful();

        $this->assertSame($secondApp->id, SentryEvent::sole()->mobile_app_id);
    }

    public function test_header_routing_resolves_custom_transport_headers(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$firstApp, $firstIdentifier] = $this->appTarget($team, 'first');
        [$secondApp, $secondIdentifier] = $this->appTarget($team, 'second');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Header);
        $dsn->update(['app_key' => 'X-App-Slug', 'identifier_key' => 'X-App-Identifier']);
        $this->route($dsn, $firstApp, $firstIdentifier);
        $this->route($dsn, $secondApp, $secondIdentifier);
        $body = $this->envelope($dsn, [
            'event_id' => str_repeat('8', 32),
            'message' => 'Header metadata',
        ]);

        $this->call('POST', route('sentry.envelope.store', $dsn->project_id), [], [], [], [
            'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$dsn->public_key}",
            'HTTP_X_APP_SLUG' => $secondApp->slug,
            'HTTP_X_APP_IDENTIFIER' => $secondIdentifier->identifier,
        ], $body)->assertSuccessful();

        $this->assertSame($secondApp->id, SentryEvent::sole()->mobile_app_id);
    }

    public function test_direct_mapping_dsn_routes_without_custom_transport_or_scope_metadata(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$firstApp, $firstIdentifier] = $this->appTarget($team, 'first');
        [$secondApp, $secondIdentifier] = $this->appTarget($team, 'second');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Query);
        $this->route($dsn, $firstApp, $firstIdentifier);
        $directRoute = $this->route($dsn, $secondApp, $secondIdentifier);
        $directDsn = $dsn->dsn($directRoute->public_key);
        $body = str_replace($dsn->dsn(), $directDsn, $this->envelope($dsn, [
            'event_id' => str_repeat('6', 32),
            'message' => 'Direct DSN route',
        ]));
        $url = route('sentry.envelope.store', $dsn->project_id)
            .'?'.http_build_query(['sentry_key' => $directRoute->public_key]);

        $this->call('POST', $url, [], [], [], [], $body)->assertSuccessful();

        $event = SentryEvent::sole();
        $this->assertSame($secondApp->id, $event->mobile_app_id);
        $this->assertSame($secondIdentifier->id, $event->app_identifier_id);
    }

    public function test_ingest_rejects_bad_public_key_and_ambiguous_routing_without_storing_payload(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$firstApp, $firstIdentifier] = $this->appTarget($team, 'first');
        [$secondApp, $secondIdentifier] = $this->appTarget($team, 'second');
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Query);
        $this->route($dsn, $firstApp, $firstIdentifier);
        $this->route($dsn, $secondApp, $secondIdentifier);
        $body = $this->envelope($dsn, ['event_id' => str_repeat('f', 32), 'message' => 'No route']);
        $baseUrl = route('sentry.envelope.store', $dsn->project_id);

        $badBody = str_replace($dsn->public_key, 'wrong', $body);
        $this->call('POST', $baseUrl.'?sentry_key=wrong', [], [], [], [], $badBody)
            ->assertUnauthorized();
        $this->call('POST', $baseUrl."?sentry_key={$dsn->public_key}", [], [], [], [], $body)
            ->assertBadRequest()
            ->assertJsonPath('detail', "The routing value 'app_slug' is missing.");

        $this->assertDatabaseCount('sentry_envelopes', 0);
        $this->assertDatabaseCount('sentry_events', 0);
    }

    public function test_forward_job_rewrites_envelope_dsn_and_marks_delivery_forwarded(): void
    {
        Http::fake(['sentry.example.test/*' => Http::response('', 200)]);
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$app, $identifier] = $this->appTarget($team, 'relay');
        $upstream = 'https://upstream-public@sentry.example.test/42';
        $dsn = $this->dsn($team, $owner, SentryRoutingMethod::Event, upstream: $upstream);
        $route = $this->route($dsn, $app, $identifier);
        $body = $this->envelope($dsn, ['event_id' => str_repeat('9', 32), 'message' => 'Relay me']);
        $envelope = SentryEnvelope::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'header' => ['dsn' => $dsn->dsn()],
            'body_base64' => base64_encode($body),
            'status' => SentryEnvelopeStatus::Pending,
        ]);

        app()->call([new ForwardSentryEnvelope($envelope->id), 'handle']);

        $envelope->refresh();
        $this->assertSame(SentryEnvelopeStatus::Forwarded, $envelope->status);
        $this->assertNotNull($envelope->forwarded_at);
        $this->assertSame(0, $dsn->refresh()->consecutive_forward_failures);
        Http::assertSent(function (HttpRequest $request) use ($upstream) {
            $firstLine = strstr($request->body(), "\n", true);
            $header = json_decode($firstLine, true);

            return str_starts_with($request->url(), 'https://sentry.example.test/api/42/envelope/?')
                && str_contains($request->url(), 'sentry_key=upstream-public')
                && $header['dsn'] === $upstream
                && str_contains($request->body(), 'Relay me');
        });
    }

    public static function eventMetadataSources(): array
    {
        return [
            'setExtra / extra' => [SentryEventSource::Extra, 'extra'],
            'setTags / tags' => [SentryEventSource::Tags, 'tags'],
            'setAttributes / attributes' => [SentryEventSource::Attributes, 'attributes'],
        ];
    }

    /** @return array{User, Team} */
    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    /** @return array{MobileApp, AppIdentifier} */
    private function appTarget(
        Team $team,
        string $slug,
        Platform $platform = Platform::Ios,
        ?string $identifier = null,
    ): array {
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => ucfirst($slug),
        ]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => $platform,
            'identifier' => $identifier ?? "com.example.{$slug}",
        ]);

        return [$app, $identifier];
    }

    private function dsn(
        Team $team,
        User $owner,
        SentryRoutingMethod $method,
        ?SentryEventSource $source = null,
        ?string $upstream = null,
    ): SentryDsn {
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Observability,
            'provider' => IntegrationProvider::Sentry,
            'name' => 'Production telemetry',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
        ]);

        return SentryDsn::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'public_key' => bin2hex(random_bytes(16)),
            'project_id' => (string) random_int(100000000, 999999999),
            'routing_method' => $method,
            'event_source' => $method === SentryRoutingMethod::Event ? ($source ?? SentryEventSource::Auto) : null,
            'app_key' => $method === SentryRoutingMethod::Event ? 'app.slug' : 'app_slug',
            'identifier_key' => $method === SentryRoutingMethod::Event ? 'app.identifier' : 'app_identifier',
            'upstream_dsn' => $upstream,
        ]);
    }

    private function route(SentryDsn $dsn, MobileApp $app, ?AppIdentifier $identifier = null): SentryDsnRoute
    {
        return SentryDsnRoute::create([
            'public_key' => bin2hex(random_bytes(16)),
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier?->id,
        ]);
    }

    private function storedEnvelope(SentryDsn $dsn, SentryDsnRoute $route): SentryEnvelope
    {
        return SentryEnvelope::create([
            'team_id' => $dsn->team_id,
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $route->mobile_app_id,
            'app_identifier_id' => $route->app_identifier_id,
            'header' => ['dsn' => $dsn->dsn()],
            'body_base64' => base64_encode('{}'),
            'status' => SentryEnvelopeStatus::Stored,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function envelope(SentryDsn $dsn, array $payload, string $type = 'event'): string
    {
        $eventId = $payload['event_id'] ?? str_repeat('1', 32);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return json_encode([
            'event_id' => $eventId,
            'dsn' => $dsn->dsn(),
            'sent_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ."\n"
            .json_encode(['type' => $type, 'length' => strlen($json)], JSON_THROW_ON_ERROR)
            ."\n{$json}\n";
    }
}
