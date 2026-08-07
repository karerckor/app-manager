<?php

namespace Tests\Unit;

use App\Support\Blueprints\JsonSchemaMatcher;
use PHPUnit\Framework\TestCase;

class JsonSchemaMatcherTest extends TestCase
{
    public function test_matches_nested_required_properties_and_types(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['owner', 'release'],
            'properties' => [
                'owner' => ['type' => 'string'],
                'release' => [
                    'type' => 'object',
                    'required' => ['channel'],
                    'properties' => ['channel' => ['enum' => ['internal', 'production']]],
                ],
            ],
        ];

        $matcher = new JsonSchemaMatcher;

        $this->assertTrue($matcher->matches([
            'owner' => 'mobile',
            'release' => ['channel' => 'production'],
        ], $schema));
        $this->assertFalse($matcher->matches([
            'owner' => 'mobile',
            'release' => [],
        ], $schema));
        $this->assertFalse($matcher->matches(null, $schema));
    }

    public function test_absent_schema_matches_any_draft_metadata(): void
    {
        $matcher = new JsonSchemaMatcher;

        $this->assertTrue($matcher->matches(null, null));
        $this->assertTrue($matcher->matches(['anything' => true], []));
    }
}
