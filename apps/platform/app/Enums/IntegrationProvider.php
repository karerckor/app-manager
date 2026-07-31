<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Expo = 'expo';
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Expo => 'Expo',
            self::Http => 'HTTP',
        };
    }
}
