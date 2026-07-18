<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Exceptions\InvalidBuildTransition;
use App\Models\BuildRecord;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_start_and_cancel_request_are_idempotent(): void
    {
        $build = BuildRecord::factory()->create();
        $lifecycle = app(BuildLifecycle::class);

        $started = $lifecycle->start($build);
        $startedAgain = $lifecycle->start($started);

        $this->assertSame(BuildStatus::Running, $startedAgain->status);
        $this->assertNotNull($startedAgain->started_at);
        $this->assertSame($started->started_at?->toISOString(), $startedAgain->started_at?->toISOString());

        $cancelRequested = $lifecycle->requestCancellation($startedAgain);
        $cancelRequestedAgain = $lifecycle->requestCancellation($cancelRequested);

        $this->assertSame(BuildStatus::CancelRequested, $cancelRequestedAgain->status);
        $this->assertNotNull($cancelRequestedAgain->cancel_requested_at);
        $this->assertSame(
            $cancelRequested->cancel_requested_at?->toISOString(),
            $cancelRequestedAgain->cancel_requested_at?->toISOString(),
        );
    }

    public function test_cancel_requested_is_not_terminal_until_provider_confirmation(): void
    {
        $build = BuildRecord::factory()->create();
        $lifecycle = app(BuildLifecycle::class);

        $requested = $lifecycle->requestCancellation($build);

        $this->assertSame(BuildStatus::CancelRequested, $requested->status);
        $this->assertNull($requested->finished_at);

        $cancelled = $lifecycle->finish($requested, BuildStatus::Cancelled);

        $this->assertSame(BuildStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->finished_at);
    }

    public function test_out_of_order_terminal_status_can_finish_a_pending_build(): void
    {
        $build = BuildRecord::factory()->create();

        $completed = app(BuildLifecycle::class)->finish($build, BuildStatus::Completed);

        $this->assertSame(BuildStatus::Completed, $completed->status);
        $this->assertNotNull($completed->finished_at);
    }

    public function test_terminal_status_cannot_be_rewritten(): void
    {
        $build = BuildRecord::factory()->create();
        $lifecycle = app(BuildLifecycle::class);
        $completed = $lifecycle->finish($build, BuildStatus::Completed);

        $this->expectException(InvalidBuildTransition::class);
        $lifecycle->finish($completed, BuildStatus::Failed);
    }

    public function test_start_after_cancel_request_does_not_revive_the_build(): void
    {
        $build = BuildRecord::factory()->create();
        $lifecycle = app(BuildLifecycle::class);

        $requested = $lifecycle->requestCancellation($build);
        $result = $lifecycle->start($requested);

        $this->assertSame(BuildStatus::CancelRequested, $result->status);
        $this->assertNull($result->started_at);
    }
}
