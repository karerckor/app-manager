<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use Illuminate\Database\QueryException;
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

    public function test_version_code_is_an_integer_and_is_only_stored_for_android(): void
    {
        $android = AppIdentifier::factory()->create([
            'platform' => Platform::Android,
            'version' => '2.3.0',
            'version_code' => 20300,
        ]);
        $ios = AppIdentifier::factory()->create([
            'platform' => Platform::Ios,
            'version' => '2.3.0',
            'version_code' => 20300,
        ]);

        $this->assertSame('2.3.0', $android->fresh()->version);
        $this->assertSame(20300, $android->fresh()->version_code);
        $this->assertNull($ios->fresh()->version_code);
    }

    public function test_build_number_is_only_stored_for_ios(): void
    {
        $ios = AppIdentifier::factory()->create([
            'platform' => Platform::Ios,
            'build_number' => '20300',
        ]);
        $android = AppIdentifier::factory()->create([
            'platform' => Platform::Android,
            'build_number' => '20300',
        ]);

        $this->assertSame('20300', $ios->fresh()->build_number);
        $this->assertNull($android->fresh()->build_number);
    }

    public function test_only_one_identifier_can_be_default_per_app_and_platform(): void
    {
        $app = MobileApp::factory()->create();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
            'is_default' => true,
        ]);

        $this->expectException(QueryException::class);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app.beta',
            'is_default' => true,
        ]);
    }

    public function test_identifier_is_unique_per_app_and_platform(): void
    {
        $app = MobileApp::factory()->create();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);

        $this->expectException(QueryException::class);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
    }
}
