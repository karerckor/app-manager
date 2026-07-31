<?php

namespace Tests\Unit;

use App\Integrations\CiCd\GitLab\GitLabPipelineDefinition;
use PHPUnit\Framework\TestCase;

class GitLabPipelineDefinitionTest extends TestCase
{
    public function test_it_parses_typed_pipeline_inputs_from_the_header(): void
    {
        $yaml = <<<'YAML'
        spec:
          inputs:
            environment:
              description: Deployment environment
              options: [staging, production]
              default: staging
            dry_run:
              type: boolean
              default: false
            app_manager_build_id:
              type: string
              regex: '^[0-9a-f-]+$'
        ---
        release:
          script: ./release
        YAML;

        $inputs = (new GitLabPipelineDefinition)->inputs($yaml);

        $this->assertSame(['staging', 'production'], $inputs['environment']['options']);
        $this->assertFalse($inputs['environment']['required']);
        $this->assertSame('boolean', $inputs['dry_run']['type']);
        $this->assertTrue($inputs['app_manager_build_id']['required']);
        $this->assertSame('^[0-9a-f-]+$', $inputs['app_manager_build_id']['regex']);
    }

    public function test_invalid_or_inputless_configuration_has_no_inputs(): void
    {
        $definition = new GitLabPipelineDefinition;

        $this->assertSame([], $definition->inputs("spec: [\n"));
        $this->assertSame([], $definition->inputs("build:\n  script: make\n"));
    }
}
