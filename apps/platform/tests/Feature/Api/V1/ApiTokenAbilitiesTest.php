<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiTokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_key_cannot_read_secrets_or_write_apps(): void
    {
        [$user, $team, $app] = $this->application();
        $this->withToken($user->createToken('Metadata', [ApiTokenAbilities::APPS_READ])->plainTextToken);

        $this->getJson("/api/v1/{$team->slug}/apps")->assertOk();
        $this->getJson($this->buildContextUrl($team, $app))->assertForbidden();
        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials")->assertForbidden();
        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds")->assertForbidden();
        $this->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Forbidden'])->assertForbidden();
    }

    public function test_build_key_can_read_context_but_cannot_mutate_apps(): void
    {
        [$user, $team, $app] = $this->application();
        $this->withToken($user->createToken('Build', ApiTokenAbilities::build())->plainTextToken);

        $this->getJson($this->buildContextUrl($team, $app))
            ->assertOk()
            ->assertJsonPath('data.app.id', $app->id);
        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds", [
            'platform' => Platform::Ios->value,
            'profile_type' => ProfileType::AppStore->value,
            'ci_provider' => 'github',
            'ci_run_id' => 'build-key-run',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}", [
            'name' => 'Forbidden',
        ])->assertForbidden();
    }

    public function test_expired_key_is_rejected(): void
    {
        [$user, $team] = $this->application();
        $token = $user->createToken(
            'Expired',
            ApiTokenAbilities::build(),
            now()->subMinute(),
        )->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/apps")
            ->assertUnauthorized();
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'is_default' => true,
        ]);

        return [$user, $team, $app];
    }

    private function buildContextUrl(Team $team, MobileApp $app): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}/build-context"
            .'?platform='.Platform::Ios->value
            .'&profile_type='.ProfileType::AppStore->value;
    }
}
