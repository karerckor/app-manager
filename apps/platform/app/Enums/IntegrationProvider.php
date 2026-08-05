<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Expo = 'expo';
    case Http = 'http';
    case Slack = 'slack';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Sentry = 'sentry';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Expo => 'Expo',
            self::Http => 'HTTP',
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Telegram => 'Telegram',
            self::Sentry => 'Sentry',
            self::Custom => 'Custom',
        };
    }
}
