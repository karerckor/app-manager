<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_mobile_app(): void
    {
        $identifier = AppIdentifier::factory()->create();

        $this->assertInstanceOf(MobileApp::class, $identifier->mobileApp);
    }

    public function test_platform_is_cast_to_enum(): void
    {
        $identifier = AppIdentifier::factory()->create(['platform' => Platform::Android]);

        $this->assertSame(Platform::Android, $identifier->fresh()->platform);
    }

    public function test_identifier_is_unique_per_app_and_platform(): void
    {
        $app = MobileApp::factory()->create();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
    }
}
