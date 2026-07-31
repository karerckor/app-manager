<?php

namespace App\Billing;

use App\Billing\Contracts\ReportsMeterEvents;
use App\Models\Team;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class CashierMeterEventReporter implements ReportsMeterEvents
{
    public function report(
        Team $team,
        string $eventName,
        int $quantity,
        string $identifier,
        DateTimeInterface $occurredAt,
    ): void {
        $team->reportMeterEvent($eventName, $quantity, [
            'identifier' => $identifier,
            'timestamp' => Carbon::instance($occurredAt)->utc()->format('Y-m-d\TH:i:s\Z'),
        ], [
            'idempotency_key' => $identifier,
        ]);
    }
}
