<?php

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Webhooks\CredentialExpiryMonitor;
use App\Jobs\Integrations\ReconcileCiCdRun;
use App\Jobs\Integrations\SyncCiCdRunLogs;
use App\Jobs\Notifications\DeliverNotification;
use App\Jobs\Notifications\PublishNotificationEvent;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\CiCdRun;
use App\Models\NotificationDelivery;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    CiCdRun::query()
        ->whereHas('team')
        ->whereHas('pipeline.mobileApp')
        ->where('reporting_mode', BuildReportingMode::Automatic->value)
        ->whereIn('dispatch_status', [CiCdDispatchStatus::Accepted->value, CiCdDispatchStatus::Unknown->value])
        ->whereNotNull('external_run_id')
        ->whereHas('buildRecord', fn ($query) => $query->whereNotIn('status', [
            BuildStatus::Completed->value,
            BuildStatus::Failed->value,
            BuildStatus::Cancelled->value,
        ]))
        ->pluck('id')
        ->each(fn (string $id) => ReconcileCiCdRun::dispatch($id));
})->everyFiveMinutes()->name('reconcile-ci-cd-runs')->withoutOverlapping();

Schedule::call(function () {
    CiCdRun::query()
        ->whereHas('team')
        ->whereHas('pipeline.mobileApp')
        ->where('reporting_mode', BuildReportingMode::Automatic->value)
        ->whereNotNull('external_run_id')
        ->whereHas('buildRecord', fn ($query) => $query
            ->whereNull('logs_updated_at')
            ->whereNull('logs_archived_at'))
        ->whereHas('buildRecord', fn ($query) => $query->whereIn('status', [
            BuildStatus::Completed->value,
            BuildStatus::Failed->value,
            BuildStatus::Cancelled->value,
        ]))
        ->limit(100)
        ->pluck('id')
        ->each(fn (string $id) => SyncCiCdRunLogs::dispatch($id));
})->everyFiveMinutes()->name('sync-ci-cd-run-logs')->withoutOverlapping();

Schedule::call(function () {
    app(CredentialExpiryMonitor::class)->recordDue();
})->hourly()->name('record-credential-expiry-events')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled')
        && (config('integrations.webhooks.enabled') || config('integrations.notifications.enabled')));

Schedule::call(function () {
    WebhookEvent::query()
        ->whereNull('published_at')
        ->limit(500)
        ->pluck('id')
        ->each(fn (string $id) => PublishWebhookEvent::dispatch($id));
})->everyMinute()->name('publish-webhook-outbox')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled') && config('integrations.webhooks.enabled'));

Schedule::call(function () {
    WebhookDelivery::query()
        ->where(fn ($query) => $query
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->orWhere(fn ($retry) => $retry
                ->where('status', WebhookDeliveryStatus::RetryScheduled->value)
                ->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($stale) => $stale
                ->where('status', WebhookDeliveryStatus::Delivering->value)
                ->where('updated_at', '<=', now()->subMinutes(15))))
        ->limit(500)
        ->pluck('id')
        ->each(fn (string $id) => DeliverWebhook::dispatch($id));
})->everyMinute()->name('recover-webhook-deliveries')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled') && config('integrations.webhooks.enabled'));

Schedule::call(function () {
    WebhookEvent::query()
        ->whereNull('notifications_published_at')
        ->limit(500)
        ->pluck('id')
        ->each(fn (string $id) => PublishNotificationEvent::dispatch($id));
})->everyMinute()->name('publish-notification-outbox')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled') && config('integrations.notifications.enabled'));

Schedule::call(function () {
    NotificationDelivery::query()
        ->where(fn ($query) => $query
            ->where('status', NotificationDeliveryStatus::Pending->value)
            ->orWhere(fn ($retry) => $retry
                ->where('status', NotificationDeliveryStatus::RetryScheduled->value)
                ->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($stale) => $stale
                ->where('status', NotificationDeliveryStatus::Delivering->value)
                ->where('updated_at', '<=', now()->subMinutes(15))))
        ->limit(500)
        ->pluck('id')
        ->each(fn (string $id) => DeliverNotification::dispatch($id));
})->everyMinute()->name('recover-notification-deliveries')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled') && config('integrations.notifications.enabled'));

Schedule::command('audits:prune')->daily()->name('prune-audits')->withoutOverlapping();
Schedule::command('build-logs:archive')->daily()->name('archive-build-logs')->withoutOverlapping();
Schedule::command('build-logs:prune-archives')->daily()->name('prune-build-log-archives')->withoutOverlapping();
Schedule::command('sentry:archive')->daily()->name('archive-sentry-telemetry')->withoutOverlapping();
Schedule::command('sentry:prune-archives')->daily()->name('prune-sentry-archives')->withoutOverlapping();
Schedule::command('data-lifecycle:purge')->daily()->name('purge-deleted-data')->withoutOverlapping();
