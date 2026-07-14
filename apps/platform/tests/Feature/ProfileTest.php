<?php

namespace Tests\Feature;

use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_app_identifier(): void
    {
        $profile = Profile::factory()->create();

        $this->assertInstanceOf(AppIdentifier::class, $profile->appIdentifier);
    }

    public function test_type_is_cast_to_enum(): void
    {
        $profile = Profile::factory()->create(['type' => ProfileType::AdHoc]);

        $this->assertSame(ProfileType::AdHoc, $profile->fresh()->type);
    }

    public function test_type_is_unique_per_app_identifier(): void
    {
        $identifier = AppIdentifier::factory()->create();
        Profile::factory()->create([
            'app_identifier_id' => $identifier->id,
            'type' => ProfileType::AppStore,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Profile::factory()->create([
            'app_identifier_id' => $identifier->id,
            'type' => ProfileType::AppStore,
        ]);
    }
}
