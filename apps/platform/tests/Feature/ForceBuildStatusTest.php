<?php

namespace Tests\Feature;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ForceBuildStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_force_a_cancel_requested_manual_build_to_cancelled(): void
    {
        Queue::fake();
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$owner, $team, $app] = $this->context();
        $runner = User::factory()->create();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $runner->id,
            'profile_type' => ProfileType::Production,
            'reporting_mode' => BuildReportingMode::Manual,
            'status' => BuildStatus::Running,
            'started_at' => now(),
            'parameters' => ['channel' => 'qa'],
            'tags' => ['demo', 'ios'],
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.builds.cancel', [$team, $app, $build]))
            ->assertRedirect();

        $this->assertSame(BuildStatus::CancelRequested, $build->refresh()->status);
        $this->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('builds.0.can_force_status', true)
                ->where('builds.0.force_status_url', route('accounts.apps.builds.status.update', [$team, $app, $build])));

        $this->patch(route('accounts.apps.builds.status.update', [$team, $app, $build]), [
            'status' => BuildStatus::Cancelled->value,
        ])->assertRedirect()
            ->assertSessionHas('status', 'Build status forced to cancelled.');

        $build->refresh();
        $this->assertSame(BuildStatus::Cancelled, $build->status);
        $this->assertNotNull($build->finished_at);
        $event = WebhookEvent::query()->where('event_type', WebhookEventType::BuildCompleted)->sole();
        $this->assertSame(BuildStatus::Cancelled->value, $event->payload['status']);
        $this->assertArrayNotHasKey('parameters', $event->payload);
        $this->assertSame(['demo', 'ios'], $event->filter_context['tags']);
        $this->assertSame($owner->id, $event->actor_user_id);
    }

    public function test_automatic_build_status_cannot_be_forced(): void
    {
        [$owner, $team, $app] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Automatic,
        ]);

        $this->actingAs($owner)
            ->patch(route('accounts.apps.builds.status.update', [$team, $app, $build]), [
                'status' => BuildStatus::Failed->value,
            ])
            ->assertStatus(409);

        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);
    }

    public function test_user_without_pipeline_permission_cannot_force_build_status(): void
    {
        [, $team, $app] = $this->context();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'reporting_mode' => BuildReportingMode::Manual,
            'status' => BuildStatus::CancelRequested,
        ]);

        $this->actingAs($contributor)
            ->patch(route('accounts.apps.builds.status.update', [$team, $app, $build]), [
                'status' => BuildStatus::Cancelled->value,
            ])
            ->assertForbidden();

        $this->assertSame(BuildStatus::CancelRequested, $build->refresh()->status);
    }

    /** @return array{User, Team, MobileApp} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$owner->fresh(), $team, $app];
    }
}
