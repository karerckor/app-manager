<?php

namespace App\Integrations\Webhooks;

use App\Enums\WebhookEventType;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WebhookEventRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        Team $team,
        WebhookEventType $eventType,
        Model|string $aggregate,
        array $payload,
        ?User $actor = null,
    ): ?WebhookEvent {
        if (! config('integrations.enabled') || ! config('integrations.webhooks.enabled')) {
            return null;
        }

        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => $eventType,
            'schema_version' => 1,
            'aggregate_type' => is_string($aggregate) ? $aggregate : class_basename($aggregate),
            'aggregate_id' => is_string($aggregate) ? null : $aggregate->getKey(),
            'actor_user_id' => $actor?->id,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        DB::afterCommit(fn () => PublishWebhookEvent::dispatch($event->id)->onQueue('webhooks'));

        return $event;
    }
}
