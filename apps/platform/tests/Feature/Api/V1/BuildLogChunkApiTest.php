<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildLogChunkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_byte_range_slices(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => 'ABCDEFGHIJ',
            'logs_updated_at' => now(),
        ]);
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::LogsRead->value])->plainTextToken;
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/logs";

        $this->withToken($token)->getJson("{$url}?offset=0&limit=4")
            ->assertOk()
            ->assertJsonPath('data.content', 'ABCD')
            ->assertJsonPath('data.meta.next_offset', 4)
            ->assertJsonPath('data.meta.total_bytes', 10);

        $this->withToken($token)->getJson("{$url}?offset=8&limit=4")
            ->assertOk()
            ->assertJsonPath('data.content', 'IJ')
            ->assertJsonPath('data.meta.next_offset', null);
    }

    public function test_single_shot_shape_without_params(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id, 'mobile_app_id' => $app->id, 'log_output' => 'hello',
        ]);
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::LogsRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.content', 'hello');
    }
}
