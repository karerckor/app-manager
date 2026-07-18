<?php

namespace Tests\Unit;

use App\Integrations\CiCd\GitHub\GitHubWorkflowDefinition;
use PHPUnit\Framework\TestCase;

class GitHubWorkflowDefinitionTest extends TestCase
{
    public function test_it_parses_typed_workflow_dispatch_inputs(): void
    {
        $yaml = <<<'YAML'
        name: Release
        on:
          push:
            branches: [main]
          workflow_dispatch:
            inputs:
              channel:
                description: Release channel
                required: true
                type: choice
                default: internal
                options: [internal, production]
              dry_run:
                type: boolean
                default: false
        jobs:
          build:
            runs-on: ubuntu-latest
            steps: []
        YAML;
        $definition = new GitHubWorkflowDefinition;

        $this->assertTrue($definition->supportsDispatch($yaml));
        $this->assertSame('choice', $definition->dispatchInputs($yaml)['channel']['type']);
        $this->assertSame(['internal', 'production'], $definition->dispatchInputs($yaml)['channel']['options']);
        $this->assertTrue($definition->dispatchInputs($yaml)['channel']['required']);
        $this->assertSame('boolean', $definition->dispatchInputs($yaml)['dry_run']['type']);
    }

    public function test_non_dispatchable_or_invalid_workflow_is_rejected(): void
    {
        $definition = new GitHubWorkflowDefinition;

        $this->assertFalse($definition->supportsDispatch("on: [push]\n"));
        $this->assertFalse($definition->supportsDispatch("on: [\n"));
        $this->assertSame([], $definition->dispatchInputs("on: [\n"));
    }

    public function test_scalar_and_list_dispatch_syntax_are_supported(): void
    {
        $definition = new GitHubWorkflowDefinition;

        $this->assertTrue($definition->supportsDispatch("on: workflow_dispatch\n"));
        $this->assertTrue($definition->supportsDispatch("on: [push, workflow_dispatch]\n"));
        $this->assertSame([], $definition->dispatchInputs("on: workflow_dispatch\n"));
    }
}
