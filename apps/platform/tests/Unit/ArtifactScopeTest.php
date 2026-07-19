<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamPermission;
use Tests\TestCase;

class ArtifactScopeTest extends TestCase
{
    public function test_artifact_scopes_map_to_expected_permissions(): void
    {
        $this->assertContains('artifacts:read', ApiKeyScope::values());
        $this->assertContains('artifacts:write', ApiKeyScope::values());
        $this->assertSame(TeamPermission::View, ApiKeyScope::ArtifactsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageApplications, ApiKeyScope::ArtifactsWrite->requiredPermission());
        $this->assertSame('Build artifacts', ApiKeyScope::ArtifactsRead->resourceLabel());
    }
}
