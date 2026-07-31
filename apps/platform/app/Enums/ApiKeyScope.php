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
    case FilesRead = 'files:read';
    case FilesWrite = 'files:write';
    case CredentialsRead = 'credentials:read';
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

    public function requiredPermission(): ?TeamPermission
    {
        return match ($this) {
            self::AppsRead, self::IdentifiersRead, self::AssetsRead,
            self::FilesRead, self::CredentialsRead, self::BuildsRead, self::LogsRead,
            self::ArtifactsRead => TeamPermission::View,
            self::AppsWrite, self::IdentifiersWrite, self::AssetsWrite,
            self::FilesWrite, self::BuildsWrite, self::LogsWrite,
            self::ArtifactsWrite => TeamPermission::ManageApplications,
            self::CredentialsWrite => TeamPermission::ManageCredentials,
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
            self::IdentifiersRead => 'List and inspect platform identifiers.',
            self::IdentifiersWrite => 'Create, update and delete platform identifiers.',
            self::AssetsRead => 'View and download application assets.',
            self::AssetsWrite => 'Upload, replace and delete application assets.',
            self::FilesRead => 'View and download files attached to an application.',
            self::FilesWrite => 'Upload, update and delete files attached to an application.',
            self::CredentialsRead => 'View credential metadata, secrets and files.',
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
