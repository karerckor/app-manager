<?php

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Integrations\ReconcileCiCdRun;
use App\Jobs\Integrations\SyncCiCdRunLogs;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\CiCdRun;
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
        ->where('reporting_mode', BuildReportingMode::Automatic->value)
        ->whereIn('dispatch_status', [CiCdDispatchStatus::Accepted->value, CiCdDispatchStatus::Unknown->value])
        ->whereNotNull('external_run_id')
        ->whereHas('buildRecord', fn ($query) => $query->whereNotIn('status', [
            BuildStatus::Completed->value,
            BuildStatus::Failed->value,
            BuildStatus::Cancelled->value,
        ]))
        ->pluck('id')
        ->each(fn (string $id) => ReconcileCiCdRun::dispatch($id)->onQueue('integrations'));
})->everyFiveMinutes()->name('reconcile-ci-cd-runs')->withoutOverlapping();

Schedule::call(function () {
    CiCdRun::query()
        ->where('reporting_mode', BuildReportingMode::Automatic->value)
        ->whereNotNull('external_run_id')
        ->whereHas('buildRecord', fn ($query) => $query->whereNull('logs_updated_at'))
        ->whereHas('buildRecord', fn ($query) => $query->whereIn('status', [
            BuildStatus::Completed->value,
            BuildStatus::Failed->value,
            BuildStatus::Cancelled->value,
        ]))
        ->limit(100)
        ->pluck('id')
        ->each(fn (string $id) => SyncCiCdRunLogs::dispatch($id)->onQueue('integrations'));
})->everyFiveMinutes()->name('sync-ci-cd-run-logs')->withoutOverlapping();

Schedule::call(function () {
    WebhookEvent::query()
        ->whereNull('published_at')
        ->limit(500)
        ->pluck('id')
        ->each(fn (string $id) => PublishWebhookEvent::dispatch($id)->onQueue('webhooks'));
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
        ->each(fn (string $id) => DeliverWebhook::dispatch($id)->onQueue('webhooks'));
})->everyMinute()->name('recover-webhook-deliveries')->withoutOverlapping()
    ->when(fn () => config('integrations.enabled') && config('integrations.webhooks.enabled'));
