<?php

namespace App\Integrations\Sentry;

use App\Models\SentryIssue;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Support\Facades\DB;

class SentryIssueStatusManager
{
    public function __construct(private readonly ActorContextManager $actors) {}

    public function setStatus(SentryIssue $issue, string $status, ActorContext $actor, ?string $comment = null): SentryIssue
    {
        return $this->actors->runAs($actor, fn () => DB::transaction(function () use ($issue, $status, $comment): SentryIssue {
            $lockedIssue = SentryIssue::query()->lockForUpdate()->findOrFail($issue->getKey());

            if ($lockedIssue->status !== $status) {
                $actor = $this->actors->current();
                $statusActivity = [
                    'status_comment' => filled($comment) ? trim($comment) : null,
                    'status_changed_at' => now(),
                    'status_changed_by_user_id' => $actor->originator?->id,
                    'status_changed_by_api_key_id' => $actor->apiKey?->id,
                ];
                $lockedIssue->update($status === 'resolved' ? [...$statusActivity,
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolved_by_user_id' => $actor->originator?->id,
                    'resolved_by_api_key_id' => $actor->apiKey?->id,
                ] : [...$statusActivity,
                    'status' => 'unresolved',
                    'resolved_at' => null,
                    'resolved_by_user_id' => null,
                    'resolved_by_api_key_id' => null,
                ]);
            }

            return $lockedIssue->refresh()->load([
                'resolvedByUser', 'resolvedByApiKey', 'statusChangedByUser', 'statusChangedByApiKey',
            ]);
        }));
    }
}
