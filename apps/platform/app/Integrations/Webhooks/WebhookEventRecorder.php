<?php

namespace App\Integrations\Webhooks;

use App\Enums\WebhookEventType;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\Team;
use App\Models\WebhookEvent;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WebhookEventRecorder
{
    public function __construct(
        private readonly ActorContextManager $actorContext,
        private readonly WebhookEventFilterContext $filterContext,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        Team $team,
        WebhookEventType $eventType,
        Model|string $aggregate,
        array $payload,
        ?ActorContext $actor = null,
    ): ?WebhookEvent {
        if (! config('integrations.enabled') || ! config('integrations.webhooks.enabled')) {
            return null;
        }

        $actor ??= $this->actorContext->current();
        $payload['actor'] = $actor->toWebhookActor();

        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => $eventType,
            'schema_version' => 2,
            'aggregate_type' => is_string($aggregate) ? $aggregate : class_basename($aggregate),
            'aggregate_id' => is_string($aggregate) ? null : $aggregate->getKey(),
            'actor_user_id' => $actor->originator?->id,
            'source' => $actor->source,
            'payload' => $payload,
            'filter_context' => $this->filterContext->for($aggregate),
            'occurred_at' => now(),
        ]);

        DB::afterCommit(fn () => PublishWebhookEvent::dispatch($event->id)->onQueue('webhooks'));

        return $event;
    }
}
