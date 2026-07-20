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
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function start(BuildRecord $build, ?DateTimeInterface $startedAt = null): BuildRecord
    {
        return DB::transaction(function () use ($build, $startedAt) {
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
                $this->ciCdActor($current),
            );

            return $current->refresh();
        });
    }

    public function requestCancellation(BuildRecord $build): BuildRecord
    {
        return DB::transaction(function () use ($build) {
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
                $this->ciCdActor($current),
            );

            return $current->refresh();
        });
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
                $actor ?? $this->ciCdActor($current),
            );

            return $current->refresh();
        });
    }

    private function ciCdActor(BuildRecord $build): ActorContext
    {
        $requester = $build->requestedBy;

        return $requester
            ? ActorContext::forUser($requester, 'ci_cd')
            : ActorContext::system();
    }

    private function lock(BuildRecord $build): BuildRecord
    {
        return BuildRecord::query()->lockForUpdate()->findOrFail($build->id);
    }
}
