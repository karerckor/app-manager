<?php

namespace App\Support\Builds;

use App\Enums\BuildStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\InvalidBuildTransition;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\BuildRecord;
use Illuminate\Support\Facades\DB;

class BuildLifecycle
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function start(BuildRecord $build): BuildRecord
    {
        return DB::transaction(function () use ($build) {
            $current = $this->lock($build);

            if ($current->status === BuildStatus::Running || $current->status !== BuildStatus::Pending) {
                return $current;
            }

            $current->update([
                'status' => BuildStatus::Running,
                'started_at' => $current->started_at ?? now(),
            ]);

            $this->webhookEvents->record(
                $current->team,
                WebhookEventType::BuildStarted,
                $current,
                WebhookPayload::build($current),
                $current->requestedBy,
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
                $current->requestedBy,
            );

            return $current->refresh();
        });
    }

    public function finish(BuildRecord $build, BuildStatus $status): BuildRecord
    {
        if (! $status->isTerminal()) {
            throw InvalidBuildTransition::from($build->status, $status);
        }

        return DB::transaction(function () use ($build, $status) {
            $current = $this->lock($build);

            if ($current->status === $status) {
                return $current;
            }

            if ($current->status->isTerminal()) {
                throw InvalidBuildTransition::from($current->status, $status);
            }

            $current->update([
                'status' => $status,
                'finished_at' => $current->finished_at ?? now(),
            ]);

            $this->webhookEvents->record(
                $current->team,
                WebhookEventType::BuildCompleted,
                $current,
                WebhookPayload::build($current),
                $current->requestedBy,
            );

            return $current->refresh();
        });
    }

    private function lock(BuildRecord $build): BuildRecord
    {
        return BuildRecord::query()->lockForUpdate()->findOrFail($build->id);
    }
}
