<?php

namespace App\Enums;

enum TagAnimation: string
{
    case Pulse = 'pulse';
    case Ping = 'ping';
    case Flash = 'flash';
    case Tada = 'tada';
    case HeartBeat = 'heartbeat';

    /**
     * Resolve a value case-insensitively, so the animate.css spelling
     * "heartBeat" is accepted and canonicalized to "heartbeat".
     */
    public static function tryFromLoose(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(mb_strtolower($value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
