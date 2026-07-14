<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPlatformRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_has_many_mobile_apps(): void
    {
        $team = Team::factory()->create();
        MobileApp::factory()->count(2)->create(['team_id' => $team->id]);

        $this->assertCount(2, $team->mobileApps);
    }

    public function test_team_has_many_credentials(): void
    {
        $team = Team::factory()->create();
        Credential::factory()->count(3)->create(['team_id' => $team->id]);

        $this->assertCount(3, $team->credentials);
    }
}
