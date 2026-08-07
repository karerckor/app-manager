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
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SentryArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 12:00:00');
        Storage::fake('media-local');
        config([
            'media-library.disk_name' => 'media-local',
            'sentry-retention.disk' => 'media-local',
            'sentry-retention.hot_retention_days' => 30,
            'sentry-retention.archive_retention_days' => 365,
            'data-lifecycle.trash_retention_days' => 90,
            'data-lifecycle.credential_trash_retention_days' => 90,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_archives_payloads_and_authorized_user_can_download_them(): void
    {
        [$user, $team, $app, $envelope, $event] = $this->telemetry();

        $this->artisan('sentry:archive')
            ->expectsOutput('Archived 1 Sentry envelope(s) older than 30 day(s).')
            ->assertSuccessful();

        $envelope->refresh();
        $event->refresh();
        $this->assertNull($envelope->body_base64);
        $this->assertNull($event->payload);
        $this->assertNotNull($envelope->archived_at);
        Storage::disk('media-local')->assertExists($envelope->archive_path);

        $document = json_decode(
            gzdecode(Storage::disk('media-local')->get($envelope->archive_path)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('app-manager-sentry-envelope-v1', $document['format']);
        $this->assertSame('transaction', $document['events'][0]['item_type']);
        $this->assertSame('navigation', $document['events'][0]['payload']['contexts']['trace']['op']);

        $this->actingAs($user)
            ->get(route('accounts.apps.telemetry.envelopes.archive', [$team, $app, $envelope]))
            ->assertOk()
            ->assertDownload("sentry-envelope-{$envelope->id}.json.gz");
    }

    public function test_prune_command_deletes_archive_and_database_payload_after_cold_retention(): void
    {
        [, , , $envelope, $event] = $this->telemetry();
        $this->artisan('sentry:archive')->assertSuccessful();
        $envelope->refresh();
        $path = $envelope->archive_path;
        $envelope->forceFill(['archived_at' => now()->subDays(366)])->saveQuietly();

        $this->artisan('sentry:prune-archives')
            ->expectsOutput('Purged 1 Sentry archive(s) older than 365 day(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sentry_envelopes', ['id' => $envelope->id]);
        $this->assertDatabaseMissing('sentry_events', ['id' => $event->id]);
        Storage::disk('media-local')->assertMissing($path);
    }

    public function test_pending_envelope_is_terminalized_at_the_hot_retention_boundary(): void
    {
        [, , , $envelope] = $this->telemetry();
        $envelope->update([
            'status' => SentryEnvelopeStatus::RetryScheduled,
            'next_forward_at' => now()->addHour(),
        ]);

        $this->artisan('sentry:archive')->assertSuccessful();

        $envelope->refresh();
        $this->assertSame(SentryEnvelopeStatus::Exhausted, $envelope->status);
        $this->assertNull($envelope->next_forward_at);
        $this->assertSame(
            'Forwarding stopped when the hot retention window expired.',
            $envelope->forwarding_error,
        );
        Storage::disk('media-local')->assertExists($envelope->archive_path);
    }

    public function test_application_purge_waits_for_sentry_cold_retention(): void
    {
        [, , $app, $envelope] = $this->telemetry();
        $this->artisan('sentry:archive')->assertSuccessful();
        $envelope->refresh();
        $path = $envelope->archive_path;

        $app->delete();
        MobileApp::onlyTrashed()->whereKey($app->id)->update([
            'deleted_at' => now()->subDays(91),
        ]);

        $this->artisan('data-lifecycle:purge')->assertSuccessful();
        $this->assertSoftDeleted('mobile_apps', ['id' => $app->id]);
        Storage::disk('media-local')->assertExists($path);

        $envelope->forceFill(['archived_at' => now()->subDays(366)])->saveQuietly();
        $this->artisan('sentry:prune-archives')->assertSuccessful();
        $this->artisan('data-lifecycle:purge')->assertSuccessful();

        $this->assertDatabaseMissing('mobile_apps', ['id' => $app->id]);
        Storage::disk('media-local')->assertMissing($path);
    }

    /** @return array{User, Team, MobileApp, SentryEnvelope, SentryEvent} */
    private function telemetry(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Web,
        ]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Observability,
            'provider' => IntegrationProvider::Sentry,
            'name' => 'Archive Sentry',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $dsn = SentryDsn::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'public_key' => bin2hex(random_bytes(16)),
            'project_id' => '987654321',
            'routing_method' => SentryRoutingMethod::Query,
            'app_key' => 'app_slug',
        ]);
        $envelope = SentryEnvelope::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'header' => ['event_id' => 'archive-event'],
            'body_base64' => base64_encode("{}\n"),
            'status' => SentryEnvelopeStatus::Stored,
        ]);
        $envelope->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
        $event = SentryEvent::create([
            'team_id' => $team->id,
            'sentry_dsn_id' => $dsn->id,
            'sentry_envelope_id' => $envelope->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'event_id' => 'archive-event',
            'item_type' => 'transaction',
            'title' => 'Archived trace',
            'trace_id' => str_repeat('a', 32),
            'occurred_at' => now()->subDays(31),
            'payload' => ['contexts' => ['trace' => ['op' => 'navigation']]],
        ]);

        return [$user, $team, $app, $envelope, $event];
    }
}
