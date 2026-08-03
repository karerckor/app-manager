<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildTagsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_build_can_be_created_with_tags(): void
    {
        [$user, $team, $app, $identifier] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $response = $this->postJson($this->api($team, $app, '/builds'), [
            'request_uuid' => (string) Str::uuid(),
            'profile_type' => ProfileType::Production->value,
            'app_identifier_id' => $identifier->id,
            'tags' => [['ios', '#3B82F6'], ['1.0.0', null]],
        ])->assertCreated();

        $this->assertSame([['ios', '#3b82f6'], ['1.0.0']], $response->json('data.tags'));
        $this->assertSame(
            [['ios', '#3b82f6'], ['1.0.0']],
            BuildRecord::findOrFail($response->json('data.id'))->tags,
        );
    }

    public function test_tags_can_be_updated_without_a_status_transition(): void
    {
        [$user, $team, $app] = $this->context();
        $build = $this->build($team, $app, BuildReportingMode::Manual);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->patchJson($this->api($team, $app, '/builds/'.$build->id), [
            'tags' => [['android', '#22C55E']],
        ])->assertOk()->assertJsonPath('data.tags', [['android', '#22c55e']]);

        $build->refresh();
        $this->assertSame([['android', '#22c55e']], $build->tags);
        $this->assertSame(BuildStatus::Pending, $build->status);
    }

    public function test_tags_and_a_status_transition_can_be_sent_together(): void
    {
        [$user, $team, $app] = $this->context();
        $build = $this->build($team, $app, BuildReportingMode::Manual);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->patchJson($this->api($team, $app, '/builds/'.$build->id), [
            'status' => BuildStatus::Running->value,
            'tags' => [['web', '#a855f7']],
        ])->assertOk()
            ->assertJsonPath('data.status', BuildStatus::Running->value)
            ->assertJsonPath('data.tags', [['web', '#a855f7']]);

        $this->assertSame([['web', '#a855f7']], $build->refresh()->tags);
    }

    public function test_an_automatic_build_accepts_tags_but_still_rejects_status_writes(): void
    {
        [$user, $team, $app] = $this->context();
        $build = $this->build($team, $app, BuildReportingMode::Eas);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);
        $url = $this->api($team, $app, '/builds/'.$build->id);

        $this->patchJson($url, ['tags' => [['expo', '#f97316']]])
            ->assertOk()
            ->assertJsonPath('data.tags', [['expo', '#f97316']]);
        $this->patchJson($url, ['status' => BuildStatus::Running->value])->assertStatus(409);

        $build->refresh();
        $this->assertSame([['expo', '#f97316']], $build->tags);
        $this->assertSame(BuildStatus::Pending, $build->status);
    }

    public function test_an_update_requires_a_status_or_tags(): void
    {
        [$user, $team, $app] = $this->context();
        $build = $this->build($team, $app, BuildReportingMode::Manual);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->patchJson($this->api($team, $app, '/builds/'.$build->id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_tags_with_an_invalid_hex_color_are_rejected(): void
    {
        [$user, $team, $app] = $this->context();
        $build = $this->build($team, $app, BuildReportingMode::Manual);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->patchJson($this->api($team, $app, '/builds/'.$build->id), [
            'tags' => [['ios', 'blue']],
        ])->assertStatus(422)->assertJsonValidationErrors('tags.0.1');

        $this->assertSame([], $build->refresh()->tags);
    }

    private function build(Team $team, MobileApp $app, BuildReportingMode $mode): BuildRecord
    {
        return BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => $mode,
        ]);
    }

    /** @return array{User, Team, MobileApp, AppIdentifier} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);

        return [$user, $team, $app, $identifier];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope) => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }

    private function api(Team $team, MobileApp $app, string $path): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}{$path}";
    }
}
