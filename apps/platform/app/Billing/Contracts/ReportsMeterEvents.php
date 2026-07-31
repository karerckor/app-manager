<?php

namespace App\Billing\Contracts;

use App\Models\Team;
use DateTimeInterface;

interface ReportsMeterEvents
{
    public function report(
        Team $team,
        string $eventName,
        int $quantity,
        string $identifier,
        DateTimeInterface $occurredAt,
    ): void;
}
