<?php

namespace App\Billing;

use App\Billing\Contracts\RecordsUsage;
use App\Jobs\Billing\ReportBillingUsage;
use App\Models\BillingUsageEvent;
use App\Models\BuildRecord;
use Illuminate\Support\Facades\DB;

final class OutboxUsageRecorder implements RecordsUsage
{
    public function recordBuildRun(BuildRecord $build): void
    {
        $team = $build->team;

        if (! $team || ! $team->subscribed(config('billing.subscription', 'default'))) {
            return;
        }

        $event = BillingUsageEvent::firstOrCreate(
            ['event_key' => "build_run:{$build->id}"],
            [
                'team_id' => $team->id,
                'meter_event_name' => config('billing.meters.build_runs.event_name'),
                'quantity' => 1,
                'occurred_at' => $build->created_at ?? now(),
                'next_attempt_at' => now(),
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(fn () => ReportBillingUsage::dispatch($event->id)
            ->onQueue(config('billing.queue', 'billing')));
    }
}
