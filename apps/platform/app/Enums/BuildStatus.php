<?php

namespace App\Enums;

enum BuildStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
