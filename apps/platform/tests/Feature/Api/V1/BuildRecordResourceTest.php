<?php

namespace Tests\Feature\Api\V1;

use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\BuildRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BuildRecordResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_dispatch_fields_null_for_manual_builds(): void
    {
        $build = BuildRecord::factory()->create();

        $array = (new BuildRecordResource($build))->toArray(Request::create('/'));

        $this->assertArrayHasKey('dispatch_status', $array);
        $this->assertArrayHasKey('external_run_url', $array);
        $this->assertNull($array['dispatch_status']);
        $this->assertNull($array['external_run_url']);
    }
}
