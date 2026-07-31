<?php

namespace Tests\Unit;

use App\Integrations\Expo\EasWorkflowDefinition;
use PHPUnit\Framework\TestCase;

class EasWorkflowDefinitionTest extends TestCase
{
    public function test_it_reads_eas_workflow_dispatch_inputs(): void
    {
        $inputs = (new EasWorkflowDefinition)->dispatchInputs(<<<'YAML'
            name: Release
            on:
              workflow_dispatch:
                inputs:
                  channel:
                    type: choice
                    required: true
                    default: preview
                    options: [preview, production]
                  dry_run:
                    type: boolean
                    default: false
                  environment:
                    type: environment
                    description: EAS environment
            jobs:
              build:
                type: build
                params:
                  platform: android
            YAML);

        $this->assertSame('choice', $inputs['channel']['type']);
        $this->assertTrue($inputs['channel']['required']);
        $this->assertSame(['preview', 'production'], $inputs['channel']['options']);
        $this->assertFalse($inputs['dry_run']['default']);
        $this->assertSame('string', $inputs['environment']['type']);
        $this->assertSame('EAS environment', $inputs['environment']['description']);
    }

    public function test_invalid_or_non_dispatchable_yaml_has_no_inputs(): void
    {
        $definition = new EasWorkflowDefinition;

        $this->assertSame([], $definition->dispatchInputs('jobs: ['));
        $this->assertSame([], $definition->dispatchInputs("jobs:\n  build:\n    type: build\n"));
    }
}
