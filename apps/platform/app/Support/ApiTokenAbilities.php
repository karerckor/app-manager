<?php

namespace App\Support;

class ApiTokenAbilities
{
    public const APPS_READ = 'apps:read';

    public const APPS_WRITE = 'apps:write';

    public const SECRETS_READ = 'secrets:read';

    public const SECRETS_WRITE = 'secrets:write';

    public const BUILDS_READ = 'builds:read';

    public const BUILDS_WRITE = 'builds:write';

    public static function all(): array
    {
        return [
            self::APPS_READ,
            self::APPS_WRITE,
            self::SECRETS_READ,
            self::SECRETS_WRITE,
            self::BUILDS_READ,
            self::BUILDS_WRITE,
        ];
    }

    public static function definitions(): array
    {
        return [
            ['value' => self::APPS_READ, 'label' => 'Read applications', 'description' => 'Apps, identifiers, assets and credential types.'],
            ['value' => self::APPS_WRITE, 'label' => 'Manage applications', 'description' => 'Create and change apps, identifiers and assets.'],
            ['value' => self::SECRETS_READ, 'label' => 'Read build secrets', 'description' => 'Build context, credential payloads and files.'],
            ['value' => self::SECRETS_WRITE, 'label' => 'Manage build secrets', 'description' => 'Create, rotate and delete credentials.'],
            ['value' => self::BUILDS_READ, 'label' => 'Read build history', 'description' => 'Build records and immutable context snapshots.'],
            ['value' => self::BUILDS_WRITE, 'label' => 'Manage builds', 'description' => 'Register, claim, log, cancel and finish CI builds.'],
        ];
    }

    public static function build(): array
    {
        return [self::APPS_READ, self::SECRETS_READ, self::BUILDS_READ, self::BUILDS_WRITE];
    }

    public static function normalize(array $abilities): array
    {
        if (in_array(self::APPS_WRITE, $abilities, true)) {
            $abilities[] = self::APPS_READ;
        }

        if (in_array(self::SECRETS_READ, $abilities, true)) {
            $abilities[] = self::APPS_READ;
        }

        if (in_array(self::SECRETS_WRITE, $abilities, true)) {
            $abilities = [
                ...$abilities,
                self::APPS_READ,
                self::SECRETS_READ,
            ];
        }

        if (in_array(self::BUILDS_READ, $abilities, true)) {
            $abilities[] = self::APPS_READ;
        }

        if (in_array(self::BUILDS_WRITE, $abilities, true)) {
            $abilities = [...$abilities, self::APPS_READ, self::BUILDS_READ];
        }

        return array_values(array_intersect(self::all(), array_unique($abilities)));
    }
}
