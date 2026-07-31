<?php

namespace App\Support\Builds;

use App\Enums\BuildStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\InvalidBuildTransition;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\BuildRecord;
use App\Support\Audit\ActorContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class BuildLifecycle
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly BuildActorResolver $actors,
    ) {}

    public function start(
        BuildRecord $build,
        ?DateTimeInterface $startedAt = null,
        ?ActorContext $actor = null,
    ): BuildRecord {
        return $this->actors->runAs($build, function (ActorContext $actor) use ($build, $startedAt) {
            return DB::transaction(function () use ($build, $startedAt, $actor) {
                $current = $this->lock($build);

                if ($current->status === BuildStatus::Running || $current->status !== BuildStatus::Pending) {
                    return $current;
                }

                $current->update([
                    'status' => BuildStatus::Running,
                    'started_at' => $current->started_at ?? $startedAt ?? now(),
                ]);

                $this->webhookEvents->record(
                    $current->team,
                    WebhookEventType::BuildStarted,
                    $current,
                    WebhookPayload::build($current),
                    $actor,
                );

                return $current->refresh();
            });
        }, $actor);
    }

    public function requestCancellation(BuildRecord $build, ?ActorContext $actor = null): BuildRecord
    {
        return $this->actors->runAs($build, function (ActorContext $actor) use ($build) {
            return DB::transaction(function () use ($build, $actor) {
                $current = $this->lock($build);

                if ($current->status === BuildStatus::CancelRequested || ! $current->status->canRequestCancellation()) {
                    return $current;
                }

                $current->update([
                    'status' => BuildStatus::CancelRequested,
                    'cancel_requested_at' => $current->cancel_requested_at ?? now(),
                ]);

                $this->webhookEvents->record(
                    $current->team,
                    WebhookEventType::BuildCancelRequested,
                    $current,
                    WebhookPayload::build($current),
                    $actor,
                );

                return $current->refresh();
            });
        }, $actor);
    }

    public function finish(
        BuildRecord $build,
        BuildStatus $status,
        ?DateTimeInterface $finishedAt = null,
        ?ActorContext $actor = null,
    ): BuildRecord {
        if (! $status->isTerminal()) {
            throw InvalidBuildTransition::from($build->status, $status);
        }

        return $this->actors->runAs($build, function (ActorContext $actor) use ($build, $status, $finishedAt) {
            return DB::transaction(function () use ($build, $status, $finishedAt, $actor) {
                $current = $this->lock($build);

                if ($current->status === $status) {
                    return $current;
                }

                if ($current->status->isTerminal()) {
                    throw InvalidBuildTransition::from($current->status, $status);
                }

                $current->update([
                    'status' => $status,
                    'finished_at' => $current->finished_at ?? $finishedAt ?? now(),
                ]);

                $this->webhookEvents->record(
                    $current->team,
                    WebhookEventType::BuildCompleted,
                    $current,
                    WebhookPayload::build($current),
                    $actor,
                );

                return $current->refresh();
            });
        }, $actor);
    }

    private function lock(BuildRecord $build): BuildRecord
    {
        return BuildRecord::query()->lockForUpdate()->findOrFail($build->id);
    }
}
