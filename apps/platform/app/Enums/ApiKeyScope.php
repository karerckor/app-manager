<?php

namespace App\Enums;

enum ApiKeyScope: string
{
    case AppsRead = 'apps:read';
    case AppsWrite = 'apps:write';
    case IdentifiersRead = 'identifiers:read';
    case IdentifiersWrite = 'identifiers:write';
    case AssetsRead = 'assets:read';
    case AssetsWrite = 'assets:write';
    case CredentialsRead = 'credentials:read';
    case CredentialsWrite = 'credentials:write';
    case BuildsRead = 'builds:read';
    case BuildsWrite = 'builds:write';
    case LogsRead = 'logs:read';
    case LogsWrite = 'logs:write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $scope): string => $scope->value,
            self::cases(),
        );
    }

    /**
     * @return list<array{key: string, label: string, scopes: list<array{value: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::cases() as $scope) {
            $resource = $scope->resource();
            $groups[$resource] ??= [
                'key' => $resource,
                'label' => $scope->resourceLabel(),
                'scopes' => [],
            ];
            $groups[$resource]['scopes'][] = [
                'value' => $scope->value,
                'label' => $scope->actionLabel(),
                'description' => $scope->description(),
            ];
        }

        return array_values($groups);
    }

    public function resource(): string
    {
        return explode(':', $this->value, 2)[0];
    }

    public function resourceLabel(): string
    {
        return match ($this->resource()) {
            'apps' => 'Applications',
            'identifiers' => 'App identifiers',
            'assets' => 'Assets',
            'credentials' => 'Credentials',
            'builds' => 'Builds',
            'logs' => 'Build logs',
        };
    }

    public function actionLabel(): string
    {
        return str_ends_with($this->value, ':read') ? 'Read' : 'Write';
    }

    public function description(): string
    {
        return match ($this) {
            self::AppsRead => 'List and inspect applications.',
            self::AppsWrite => 'Create, update and delete applications.',
            self::IdentifiersRead => 'List and inspect platform identifiers.',
            self::IdentifiersWrite => 'Create, update and delete platform identifiers.',
            self::AssetsRead => 'View and download application assets.',
            self::AssetsWrite => 'Upload, replace and delete application assets.',
            self::CredentialsRead => 'View credential metadata, secrets and files.',
            self::CredentialsWrite => 'Create, rotate and delete credentials.',
            self::BuildsRead => 'View build history and build details.',
            self::BuildsWrite => 'Create, start, update and cancel builds.',
            self::LogsRead => 'Read build log entries.',
            self::LogsWrite => 'Append build log entries.',
        };
    }
}
