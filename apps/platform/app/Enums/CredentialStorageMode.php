<?php

namespace App\Enums;

enum CredentialStorageMode: string
{
    case Local = 'local';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Stored in App Manager',
            self::External => 'Referenced in your vault',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $mode): string => $mode->value, self::cases());
    }
}
