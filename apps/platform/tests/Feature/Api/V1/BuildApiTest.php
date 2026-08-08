<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Integrations\RequestPipelineBuildCancellation;
use App\Enums\ApiKeyScope;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_can_report_the_complete_manual_build_lifecycle_and_logs(): void
    {
        [$user, $team, $app, $identifier] = $this->context();
        $this->authenticate($user, $team, [
            ApiKeyScope::BuildsWrite,
            ApiKeyScope::LogsWrite,
        ]);
        $requestUuid = (string) Str::uuid();
        $url = $this->api($team, $app, '/builds');
        $payload = [
            'request_uuid' => $requestUuid,
            'profile_type' => ProfileType::Production->value,
            'app_identifier_id' => $identifier->id,
            'context' => ['commit' => str_repeat('a', 40), 'runner' => 'self-hosted'],
        ];

        $created = $this->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', BuildStatus::Pending->value)
            ->assertJsonPath('data.reporting_mode', BuildReportingMode::Manual->value)
            ->assertJsonPath('data.context.context.runner', 'self-hosted');
        $buildId = $created->json('data.id');

        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $buildId);
        $this->assertDatabaseCount('build_records', 1);

        $buildUrl = $url.'/'.$buildId;
        $this->patchJson($buildUrl, ['status' => BuildStatus::Running->value])
            ->assertOk()
            ->assertJsonPath('data.status', BuildStatus::Running->value);
        $this->postJson($buildUrl.'/logs', ['content' => "compile: started\n"])
            ->assertOk()
            ->assertJsonPath('data.bytes', 17);
        $this->postJson($buildUrl.'/logs', ['content' => "compile: finished\n"])
            ->assertOk()
            ->assertJsonPath('data.bytes', 35);
        $this->patchJson($buildUrl, ['status' => BuildStatus::Completed->value])
            ->assertOk()
            ->assertJsonPath('data.status', BuildStatus::Completed->value);

        $build = BuildRecord::findOrFail($buildId);
        $this->assertSame("compile: started\ncompile: finished\n", $build->log_output);
        $this->assertNotNull($build->started_at);
        $this->assertNotNull($build->finished_at);
        $this->assertNotNull($build->logs_updated_at);
    }

    public function test_runner_can_create_a_manual_build_in_a_terminal_status(): void
    {
        Queue::fake();
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);
        $url = $this->api($team, $app, '/builds');

        foreach ([BuildStatus::Completed, BuildStatus::Failed] as $status) {
            $response = $this->postJson($url, [
                'request_uuid' => (string) Str::uuid(),
                'profile_type' => ProfileType::Production->value,
                'status' => $status->value,
            ])
                ->assertCreated()
                ->assertJsonPath('data.status', $status->value)
                ->assertJsonPath('data.started_at', null);

            $build = BuildRecord::query()->findOrFail($response->json('data.id'));
            $this->assertSame($status, $build->status);
            $this->assertNotNull($build->finished_at);
        }

        $this->assertSame(2, WebhookEvent::query()->where('event_type', WebhookEventType::BuildCreated)->count());
        $this->assertSame(2, WebhookEvent::query()->where('event_type', WebhookEventType::BuildCompleted)->count());
    }

    public function test_terminal_status_cannot_be_set_when_creating_an_eas_build(): void
    {
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->postJson($this->api($team, $app, '/builds'), [
            'request_uuid' => (string) Str::uuid(),
            'profile_type' => ProfileType::Production->value,
            'reporting_mode' => 'eas',
            'expo_build_id' => 'eas-build-id',
            'status' => BuildStatus::Completed->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('build_records', 0);
    }

    public function test_build_and_log_read_scopes_are_independent(): void
    {
        [$user, $team, $app] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Manual,
            'log_output' => "hello\n",
        ]);
        $buildUrl = $this->api($team, $app, '/builds/'.$build->id);

        $this->authenticate($user, $team, [ApiKeyScope::BuildsRead]);
        $this->getJson($buildUrl)->assertOk()->assertJsonMissing(['log_output' => "hello\n"]);
        $this->getJson($buildUrl.'/logs')->assertForbidden();

        $this->authenticate($user, $team, [ApiKeyScope::LogsRead]);
        $this->getJson($buildUrl.'/logs')->assertOk()->assertJsonPath('data.content', "hello\n");
        $this->getJson($buildUrl)->assertForbidden();
    }

    public function test_automatic_build_rejects_manual_status_and_log_writes(): void
    {
        [$user, $team, $app] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Automatic,
        ]);
        $this->authenticate($user, $team, [
            ApiKeyScope::BuildsWrite,
            ApiKeyScope::LogsWrite,
        ]);
        $buildUrl = $this->api($team, $app, '/builds/'.$build->id);

        $this->patchJson($buildUrl, ['status' => BuildStatus::Running->value])->assertStatus(409);
        $this->postJson($buildUrl.'/logs', ['content' => 'spoofed'])->assertStatus(409);
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);
        $this->assertNull($build->log_output);
    }

    public function test_automatic_build_cannot_be_switched_to_eas_reporting(): void
    {
        [$user, $team, $app] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Automatic,
        ]);
        $this->authenticate($user, $team, [ApiKeyScope::BuildsWrite]);

        $this->patchJson($this->api($team, $app, '/builds/'.$build->id), [
            'expo_build_id' => 'eas-build-id',
        ])->assertStatus(409);

        $build->refresh();
        $this->assertSame(BuildReportingMode::Automatic, $build->reporting_mode);
        $this->assertNull($build->expo_build_id);
    }

    public function test_cancelling_an_api_build_waits_for_runner_confirmation(): void
    {
        [, $team, $app] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Manual,
            'status' => BuildStatus::Running,
            'started_at' => now(),
        ]);

        $result = app(RequestPipelineBuildCancellation::class)->handle($build);

        $this->assertSame(BuildStatus::CancelRequested, $result->status);
        $this->assertNull($result->finished_at);
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
