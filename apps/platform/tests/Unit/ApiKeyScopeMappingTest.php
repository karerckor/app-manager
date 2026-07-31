<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamPermission;
use PHPUnit\Framework\TestCase;

class ApiKeyScopeMappingTest extends TestCase
{
    public function test_new_scopes_exist(): void
    {
        $values = ApiKeyScope::values();

        foreach ([
            'integrations:read', 'integrations:write', 'integrations:proxy',
            'pipelines:read', 'pipelines:write', 'pipelines:run',
            'webhooks:read', 'webhooks:write',
            'deliveries:read',
            'api-keys:read', 'api-keys:write',
        ] as $scope) {
            $this->assertContains($scope, $values);
        }
    }

    public function test_required_permission_maps_scope_to_permission(): void
    {
        $this->assertSame(TeamPermission::View, ApiKeyScope::AppsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageApplications, ApiKeyScope::AppsWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageCredentials, ApiKeyScope::CredentialsWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::IntegrationsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::IntegrationsWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::IntegrationsProxy->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::PipelinesWrite->requiredPermission());
        $this->assertSame(TeamPermission::RunPipelines, ApiKeyScope::PipelinesRun->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::WebhooksWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::DeliveriesRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageTeam, ApiKeyScope::ApiKeysRead->requiredPermission());
        $this->assertNull(ApiKeyScope::ApiKeysWrite->requiredPermission());
    }
}
