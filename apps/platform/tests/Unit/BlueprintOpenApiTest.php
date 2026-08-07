<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class BlueprintOpenApiTest extends TestCase
{
    public function test_openapi_documents_every_blueprint_operation_and_scope(): void
    {
        $document = Yaml::parseFile(dirname(__DIR__, 3).'/docs/public/openapi.yaml');

        $expectedOperations = [
            '/blueprints' => ['get', 'post'],
            '/blueprints/{blueprint}' => ['get', 'patch', 'delete'],
            '/blueprints/{blueprint}/evaluate' => ['post'],
            '/blueprints/{blueprint}/hooks' => ['get', 'post'],
            '/blueprints/{blueprint}/hooks/{hook}' => ['get', 'patch', 'delete'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            $this->assertArrayHasKey($path, $document['paths']);

            foreach ($methods as $method) {
                $this->assertArrayHasKey($method, $document['paths'][$path]);
            }
        }

        $scopes = $document['components']['schemas']['ApiKeyScope']['enum'];
        $this->assertContains('blueprints:read', $scopes);
        $this->assertContains('blueprints:write', $scopes);
        $this->assertSame(
            '#/components/schemas/TagTuple',
            $document['components']['schemas']['BlueprintHookAction']['properties']['tags']['items']['$ref'],
        );
    }
}
