<?php

namespace Tests\Feature;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\SentryEnvelopeStatus;
use App\Enums\SentryRoutingMethod;
use App\Models\AppIdentifier;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\SentryDsn;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppTelemetryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.enabled' => true,
            'integrations.sentry.enabled' => true,
        ]);
    }

    public function test_application_page_lists_only_its_errors_and_traces_and_links_to_detail_pages(): void
    {
        [$user, $team] = $this->userWithTeam();
        [$app, $identifier, $dsn] = $this->sentryTarget($team, $user, 'storefront');
        [$otherApp, $otherIdentifier, $otherDsn] = $this->sentryTarget($team, $user, 'admin');
        $traceId = str_repeat('a', 32);

        $errorEnvelope = $this->envelope($team, $app, $identifier, $dsn);
        $error = SentryEvent::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'sentry_envelope_id' => $errorEnvelope->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'event_id' => str_repeat('e', 32),
            'item_type' => 'event',
            'level' => 'error',
            'title' => 'TypeError: inventory expired',
            'release' => 'storefront@1.4.0',
            'environment' => 'production',
            'platform' => 'node',
            'trace_id' => $traceId,
            'occurred_at' => now()->subSecond(),
            'payload' => [
                'tags' => array_merge(
                    ['feature' => 'checkout'],
                    collect(range(1, 21))->mapWithKeys(fn ($index) => ["tag_{$index}" => "value_{$index}"])->all(),
                ),
                'extra' => ['cart_id' => 'cart-42'],
                'sdk' => ['name' => 'sentry.javascript.node', 'version' => '9.47.1'],
                'contexts' => [
                    'runtime' => ['name' => 'node', 'version' => '24.0.0'],
                    'app' => ['app_name' => 'Storefront', 'app_memory' => 134217728],
                ],
                'request' => [
                    'method' => 'POST',
                    'url' => 'https://storefront.example.test/checkout',
                    'query_string' => 'coupon=SAVE20',
                    'headers' => [['Content-Type', 'application/json']],
                    'data' => ['cart_id' => 'cart-42'],
                ],
                'user' => [
                    'id' => 'user-42',
                    'email' => 'buyer@example.test',
                    'subscription' => 'enterprise',
                ],
                'breadcrumbs' => [['category' => 'checkout', 'message' => 'Reservation started']],
                'exception' => ['values' => [
                    [
                        'type' => 'TypeError',
                        'value' => 'inventory expired',
                        'mechanism' => ['handled' => true, 'type' => 'generic'],
                        'stacktrace' => ['frames' => [[
                            'filename' => '/srv/checkout.js',
                            'function' => 'reserveInventory',
                            'lineno' => 42,
                            'colno' => 9,
                            'in_app' => true,
                            'pre_context' => ['const inventory = await loadInventory();', 'try {'],
                            'context_line' => '  await reserveInventory(inventory);',
                            'post_context' => ['} catch (error) {', '  captureException(error);'],
                        ]]],
                    ],
                    [
                        'type' => 'InventoryError',
                        'value' => 'reservation expired',
                        'module' => 'inventory',
                    ],
                ]],
            ],
        ]);
        $traceEnvelope = $this->envelope($team, $app, $identifier, $dsn);
        $trace = SentryEvent::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'sentry_envelope_id' => $traceEnvelope->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'event_id' => str_repeat('t', 32),
            'item_type' => 'transaction',
            'level' => 'info',
            'title' => 'POST /checkout',
            'transaction' => 'POST /checkout',
            'release' => 'storefront@1.4.0',
            'environment' => 'production',
            'platform' => 'node',
            'trace_id' => $traceId,
            'occurred_at' => now(),
            'payload' => [
                'start_timestamp' => 100.25,
                'timestamp' => 101.5,
                'contexts' => ['trace' => ['trace_id' => $traceId, 'op' => 'http.server', 'status' => 'internal_error']],
                'spans' => [['span_id' => str_repeat('b', 16)]],
            ],
        ]);

        $otherEnvelope = $this->envelope($team, $otherApp, $otherIdentifier, $otherDsn);
        SentryEvent::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $otherDsn->id,
            'sentry_envelope_id' => $otherEnvelope->id,
            'mobile_app_id' => $otherApp->id,
            'app_identifier_id' => $otherIdentifier->id,
            'event_id' => str_repeat('o', 32),
            'item_type' => 'event',
            'title' => 'Other application error',
            'occurred_at' => now(),
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Telemetry/Index')
                ->where('active', 'telemetry')
                ->where('app.urls.telemetry_page', route('accounts.apps.telemetry.index', [$team, $app]))
                ->where('telemetry.stats.issues', 1)
                ->where('telemetry.stats.errors', 1)
                ->where('telemetry.stats.traces', 1)
                ->where('telemetry.stats.environments', 1)
                ->has('telemetry.issues', 1)
                ->where('telemetry.issues.0.events_count', 1)
                ->where('telemetry.issues.0.url', route('accounts.apps.telemetry.issues.show', [$team, $app, $app->sentryIssues()->sole()]))
                ->has('telemetry.traces', 1)
                ->where('telemetry.traces.0.trace_id', $traceId));

        $issue = $app->sentryIssues()->sole();
        $this->actingAs($user)->get(route('accounts.apps.telemetry.issues.show', [$team, $app, $issue]))
            ->assertSuccessful()->assertInertia(fn (Assert $page) => $page
            ->component('Apps/Telemetry/Detail')->where('telemetry.kind', 'issue')
            ->where('telemetry.selected_event.id', $error->id)->has('telemetry.selected_event.exceptions', 2)
            ->where('telemetry.selected_event.exceptions.0.frames.0.function', 'reserveInventory')
            ->where('telemetry.selected_event.request.data.cart_id', 'cart-42')
            ->where('telemetry.selected_event.user.subscription', 'enterprise'));
        $this->actingAs($user)->get(route('accounts.apps.telemetry.traces.show', [$team, $app, $traceId]))
            ->assertSuccessful()->assertInertia(fn (Assert $page) => $page
            ->component('Apps/Telemetry/Detail')->where('telemetry.kind', 'trace')
            ->where('telemetry.selected_event.id', $trace->id)->where('telemetry.selected_event.trace.duration_ms', 1250));
    }

    public function test_application_telemetry_filters_by_type_environment_and_search(): void
    {
        [$user, $team] = $this->userWithTeam();
        [$app, $identifier, $dsn] = $this->sentryTarget($team, $user, 'storefront');

        foreach ([
            ['event', 'production', 'Inventory checkout failed'],
            ['event', 'staging', 'Staging checkout failed'],
            ['transaction', 'production', 'POST /checkout'],
        ] as [$type, $environment, $title]) {
            $envelope = $this->envelope($team, $app, $identifier, $dsn);
            SentryEvent::create([
                'team_id' => $team->id,
                'sentry_dsn_id' => $dsn->id,
                'sentry_envelope_id' => $envelope->id,
                'mobile_app_id' => $app->id,
                'app_identifier_id' => $identifier->id,
                'event_id' => bin2hex(random_bytes(16)),
                'item_type' => $type,
                'title' => $title,
                'transaction' => $type === 'transaction' ? $title : null,
                'environment' => $environment,
                'occurred_at' => now(),
                'payload' => [],
            ]);
        }

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.index', [
                $team,
                $app,
                'view' => 'errors',
                'environment' => 'production',
                'search' => 'Inventory',
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('telemetry.filters.view', 'issues')
                ->where('telemetry.filters.environment', 'production')
                ->where('telemetry.filters.search', 'Inventory')
                ->where('telemetry.matching_count', 1)
                ->has('telemetry.issues', 1)
                ->where('telemetry.issues.0.title', 'Inventory checkout failed'));
    }

    public function test_application_telemetry_filters_by_multiple_app_identifiers(): void
    {
        [$user, $team] = $this->userWithTeam();
        [$app, $webIdentifier, $dsn] = $this->sentryTarget($team, $user, 'storefront');
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

        foreach ([$webIdentifier, $iosIdentifier, $androidIdentifier] as $index => $identifier) {
            $envelope = $this->envelope($team, $app, $identifier, $dsn);
            SentryEvent::create([
                'team_id' => $team->id,
                'sentry_dsn_id' => $dsn->id,
                'sentry_envelope_id' => $envelope->id,
                'mobile_app_id' => $app->id,
                'app_identifier_id' => $identifier->id,
                'event_id' => str_repeat((string) ($index + 1), 32),
                'item_type' => 'event',
                'title' => "Target {$identifier->platform->value}",
                'occurred_at' => now()->addSeconds($index),
                'payload' => [],
            ]);
        }

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.index', [
                $team,
                $app,
                'identifiers' => [$webIdentifier->id, $iosIdentifier->id],
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('telemetry.filters.identifiers', [$webIdentifier->id, $iosIdentifier->id])
                ->where('telemetry.matching_count', 2)
                ->has('telemetry.issues', 2)
                ->where('telemetry.issues.0.identifier_platform', 'ios')
                ->where('telemetry.issues.1.identifier_platform', 'web'));
    }

    public function test_matching_error_events_are_grouped_into_one_issue_without_losing_occurrences(): void
    {
        [$user, $team] = $this->userWithTeam();
        [$app, $identifier, $dsn] = $this->sentryTarget($team, $user, 'storefront');

        foreach (['a', 'b'] as $suffix) {
            $envelope = $this->envelope($team, $app, $identifier, $dsn);
            SentryEvent::create([
                'team_id' => $team->id,
                'sentry_dsn_id' => $dsn->id,
                'sentry_envelope_id' => $envelope->id,
                'mobile_app_id' => $app->id,
                'app_identifier_id' => $identifier->id,
                'event_id' => str_repeat($suffix, 32),
                'item_type' => 'event',
                'level' => 'error',
                'title' => 'Payment authorization failed for order 42',
                'occurred_at' => now()->addSecond($suffix === 'b' ? 1 : 0),
                'payload' => [
                    'exception' => ['values' => [[
                        'type' => 'PaymentAuthorizationError',
                        'value' => 'Payment authorization failed for order 42',
                        'stacktrace' => ['frames' => [[
                            'filename' => '/srv/checkout.js',
                            'function' => 'authorizePayment',
                            'in_app' => true,
                        ]]],
                    ]]],
                ],
            ]);
        }

        $issue = SentryIssue::sole();

        $this->assertSame(2, $issue->events_count);
        $this->assertSame(2, $issue->events()->count());
        $this->assertDatabaseCount('sentry_issues', 1);

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('telemetry.stats.issues', 1)
                ->where('telemetry.stats.errors', 2)
                ->has('telemetry.issues', 1)
                ->where('telemetry.issues.0.events_count', 2));
    }

    public function test_application_telemetry_is_hidden_when_sentry_integration_is_disabled(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        config(['integrations.sentry.enabled' => false]);

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.index', [$team, $app]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('accounts.apps.show', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page->where('app.urls.telemetry_page', null));
    }

    /** @return array{User, Team} */
    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    /** @return array{MobileApp, AppIdentifier, SentryDsn} */
    private function sentryTarget(Team $team, User $user, string $slug): array
    {
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => $slug]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Web,
            'identifier' => "{$slug}.example.test",
        ]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Observability,
            'provider' => IntegrationProvider::Sentry,
            'name' => ucfirst($slug).' telemetry',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $dsn = SentryDsn::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'public_key' => bin2hex(random_bytes(16)),
            'project_id' => (string) random_int(100000000, 999999999),
            'routing_method' => SentryRoutingMethod::Query,
            'app_key' => 'app_slug',
            'identifier_key' => 'app_identifier',
        ]);

        return [$app, $identifier, $dsn];
    }

    private function envelope(Team $team, MobileApp $app, AppIdentifier $identifier, SentryDsn $dsn): SentryEnvelope
    {
        return SentryEnvelope::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'header' => [],
            'body_base64' => base64_encode('{}'),
            'status' => SentryEnvelopeStatus::Stored,
        ]);
    }
}
