<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_app_belongs_to_a_team(): void
    {
        $app = MobileApp::factory()->create();

        $this->assertInstanceOf(Team::class, $app->team);
    }

    public function test_slug_is_unique_per_team(): void
    {
        $team = Team::factory()->create();
        MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'my-app']);

        $this->expectException(QueryException::class);
        MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'my-app']);
    }

    public function test_registers_icon_media_collections_as_single_file(): void
    {
        $app = MobileApp::factory()->create();
        $names = $app->getRegisteredMediaCollections()->pluck('name');

        $this->assertTrue($names->contains('app_icon'));
        $this->assertTrue($names->contains('splash_icon'));
        $appIcon = $app->getRegisteredMediaCollections()->firstWhere('name', 'app_icon');
        $this->assertTrue($appIcon->singleFile);
    }

    public function test_metadata_is_cast_to_an_array(): void
    {
        $metadata = [
            'owner' => 'mobile-team',
            'release' => ['channel' => 'production', 'automatic' => true],
        ];

        $app = MobileApp::factory()->create(['metadata' => $metadata]);

        $this->assertSame($metadata, $app->fresh()->metadata);
    }
}
