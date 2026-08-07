<?php

namespace App\Enums;

enum ApiKeyScope: string
{
    case AppsRead = 'apps:read';
    case AppsWrite = 'apps:write';
    case BlueprintsRead = 'blueprints:read';
    case BlueprintsWrite = 'blueprints:write';
    case IdentifiersRead = 'identifiers:read';
    case IdentifiersWrite = 'identifiers:write';
    case AssetsRead = 'assets:read';
    case AssetsWrite = 'assets:write';
    case FilesRead = 'files:read';
    case FilesWrite = 'files:write';
    case CredentialsRead = 'credentials:read';
    case CredentialsReveal = 'credentials:reveal';
    case CredentialsVerify = 'credentials:verify';
    case CredentialsWrite = 'credentials:write';
    case BuildsRead = 'builds:read';
    case BuildsWrite = 'builds:write';
    case LogsRead = 'logs:read';
    case LogsWrite = 'logs:write';
    case ArtifactsRead = 'artifacts:read';
    case ArtifactsWrite = 'artifacts:write';
    case IntegrationsRead = 'integrations:read';
    case IntegrationsWrite = 'integrations:write';
    case IntegrationsProxy = 'integrations:proxy';
    case PipelinesRead = 'pipelines:read';
    case PipelinesWrite = 'pipelines:write';
    case PipelinesRun = 'pipelines:run';
    case WebhooksRead = 'webhooks:read';
    case WebhooksWrite = 'webhooks:write';
    case DeliveriesRead = 'deliveries:read';
    case ApiKeysRead = 'api-keys:read';
    case ApiKeysWrite = 'api-keys:write';

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

    /** @return list<string> */
    public static function assignableValues(): array
    {
        if (CredentialApiProfile::current()->exposesSecretMaterial()) {
            return self::values();
        }

        return array_values(array_filter(
            self::values(),
            fn (string $scope): bool => $scope !== self::CredentialsReveal->value,
        ));
    }

    /**
     * @return list<array{key: string, label: string, scopes: list<array{value: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        $assignable = self::assignableValues();
        $groups = [];

        foreach (self::cases() as $scope) {
            if (! in_array($scope->value, $assignable, true)) {
                continue;
            }

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

    /**
     * @return list<array{key: string, name: string, label: string, description: string, scopes: list<string>}>
     */
    public static function presets(): array
    {
        return [
            [
                'key' => 'build-runner',
                'name' => 'Build runner',
                'label' => 'Build runner',
                'description' => 'Updates a build and streams its logs. A minimal key for a dedicated runner.',
                'scopes' => [self::BuildsWrite->value, self::LogsWrite->value],
            ],
            [
                'key' => 'ci-cd-pipeline',
                'name' => 'CI/CD pipeline',
                'label' => 'CI/CD pipeline',
                'description' => 'Reads release inputs and credentials, then reports builds, logs, and artifacts.',
                'scopes' => array_values(array_intersect([
                    self::AppsRead->value,
                    self::IdentifiersRead->value,
                    self::AssetsRead->value,
                    self::FilesRead->value,
                    self::CredentialsRead->value,
                    self::CredentialsReveal->value,
                    self::CredentialsVerify->value,
                    self::BuildsWrite->value,
                    self::LogsWrite->value,
                    self::ArtifactsWrite->value,
                ], self::assignableValues())),
            ],
        ];
    }

    public function requiredPermission(): ?TeamPermission
    {
        return match ($this) {
            self::AppsRead, self::BlueprintsRead, self::IdentifiersRead, self::AssetsRead,
            self::FilesRead, self::CredentialsRead, self::BuildsRead, self::LogsRead,
            self::ArtifactsRead => TeamPermission::View,
            self::AppsWrite, self::BlueprintsWrite, self::IdentifiersWrite, self::AssetsWrite,
            self::FilesWrite, self::BuildsWrite, self::LogsWrite,
            self::ArtifactsWrite => TeamPermission::ManageApplications,
            self::CredentialsReveal, self::CredentialsVerify, self::CredentialsWrite => TeamPermission::ManageCredentials,
            self::IntegrationsRead, self::IntegrationsWrite, self::IntegrationsProxy,
            self::PipelinesRead, self::PipelinesWrite,
            self::WebhooksRead, self::WebhooksWrite,
            self::DeliveriesRead => TeamPermission::ManageIntegrations,
            self::PipelinesRun => TeamPermission::RunPipelines,
            self::ApiKeysRead => TeamPermission::ManageTeam,
            self::ApiKeysWrite => null,
        };
    }

    public function resource(): string
    {
        return explode(':', $this->value, 2)[0];
    }

    public function resourceLabel(): string
    {
        return match ($this->resource()) {
            'apps' => 'Applications',
            'blueprints' => 'Blueprints',
            'identifiers' => 'App identifiers',
            'assets' => 'Assets',
            'files' => 'App files',
            'credentials' => 'Credentials',
            'builds' => 'Builds',
            'logs' => 'Build logs',
            'artifacts' => 'Build artifacts',
            'integrations' => 'Integrations',
            'pipelines' => 'Pipelines',
            'webhooks' => 'Webhooks',
            'deliveries' => 'Webhook deliveries',
            'api-keys' => 'API keys',
        };
    }

    public function actionLabel(): string
    {
        return match (true) {
            str_ends_with($this->value, ':read') => 'Read',
            str_ends_with($this->value, ':run') => 'Run',
            str_ends_with($this->value, ':proxy') => 'Proxy',
            default => 'Write',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AppsRead => 'List and inspect applications.',
            self::AppsWrite => 'Create, update and delete applications.',
            self::BlueprintsRead => 'List and inspect blueprints and conditional hooks.',
            self::BlueprintsWrite => 'Create, update, evaluate and delete blueprints and conditional hooks.',
            self::IdentifiersRead => 'List and inspect platform identifiers.',
            self::IdentifiersWrite => 'Create, update and delete platform identifiers.',
            self::AssetsRead => 'View and download application assets.',
            self::AssetsWrite => 'Upload, replace and delete application assets.',
            self::FilesRead => 'View and download files attached to an application.',
            self::FilesWrite => 'Upload, update and delete files attached to an application.',
            self::CredentialsRead => 'View credential metadata.',
            self::CredentialsReveal => 'Reveal credential secrets and download credential files.',
            self::CredentialsVerify => 'Report whether a vault reference resolved during a build.',
            self::CredentialsWrite => 'Create, rotate and delete credentials.',
            self::BuildsRead => 'View build history and build details.',
            self::BuildsWrite => 'Create, start, update and cancel builds.',
            self::LogsRead => 'Read build log entries.',
            self::LogsWrite => 'Append build log entries.',
            self::ArtifactsRead => 'View and download build artifacts.',
            self::ArtifactsWrite => 'Upload and delete build artifacts.',
            self::IntegrationsRead => 'List integrations and discover provider resources.',
            self::IntegrationsWrite => 'Connect, sync and disconnect integrations or perform provider mutations.',
            self::IntegrationsProxy => 'Forward arbitrary authenticated provider requests, including access to remote secrets and mutations.',
            self::PipelinesRead => 'View pipeline configurations and their inputs.',
            self::PipelinesWrite => 'Create, update and delete pipeline configurations.',
            self::PipelinesRun => 'Dispatch and cancel automated builds.',
            self::WebhooksRead => 'View webhook endpoints.',
            self::WebhooksWrite => 'Create, update, rotate, test and delete webhook endpoints.',
            self::DeliveriesRead => 'View webhook delivery history.',
            self::ApiKeysRead => 'List team API keys.',
            self::ApiKeysWrite => 'Create and revoke team API keys.',
        };
    }
}
