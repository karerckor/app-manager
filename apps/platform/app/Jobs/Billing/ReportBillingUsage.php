<?php

namespace App\Jobs\Billing;

use App\Billing\BillingConfiguration;
use App\Billing\Contracts\ReportsMeterEvents;
use App\Models\BillingUsageEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ReportBillingUsage implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly string $usageEventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 1800, 3600];
    }

    public function handle(ReportsMeterEvents $reporter): void
    {
        if (! BillingConfiguration::enabled()) {
            return;
        }

        BillingConfiguration::assertReady();

        Cache::lock("billing-usage:{$this->usageEventId}", 120)->get(function () use ($reporter): void {
            $event = BillingUsageEvent::query()->with('team')->find($this->usageEventId);

            if (! $event || $event->reported_at) {
                return;
            }

            $event->increment('attempts');

            try {
                $reporter->report(
                    $event->team,
                    $event->meter_event_name,
                    $event->quantity,
                    $event->event_key,
                    $event->occurred_at,
                );
            } catch (Throwable $exception) {
                $event->forceFill([
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                    'next_attempt_at' => now()->addSeconds($this->retryDelay($event->attempts)),
                ])->save();

                throw $exception;
            }

            $event->forceFill([
                'reported_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();
        });
    }

    private function retryDelay(int $attempts): int
    {
        $delays = $this->backoff();

        return $delays[min(max($attempts - 1, 0), count($delays) - 1)];
    }
}
