<?php

namespace App\Integrations\Webhooks;

use App\Enums\CredentialApiProfile;
use App\Enums\WebhookEventType;
use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Jobs\Notifications\PublishNotificationEvent;
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
        private readonly ExpoAutoSyncDispatcher $expoAutoSync,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        Team $team,
        WebhookEventType $eventType,
        Model|string $aggregate,
        array $payload,
        ?ActorContext $actor = null,
        bool $dispatchExpoSync = true,
    ): ?WebhookEvent {
        if ($dispatchExpoSync && $aggregate instanceof Model) {
            $this->expoAutoSync->dispatchForAggregate($aggregate);
        }

        if ($eventType->isCredentialEvent()
            && ! CredentialApiProfile::current()->exposesSecretMaterial()) {
            return null;
        }

        $webhooksEnabled = (bool) config('integrations.webhooks.enabled');
        $notificationsEnabled = (bool) config('integrations.notifications.enabled');

        if (! config('integrations.enabled') || (! $webhooksEnabled && ! $notificationsEnabled)) {
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
            'published_at' => $webhooksEnabled ? null : now(),
            'notifications_published_at' => $notificationsEnabled ? null : now(),
        ]);

        if ($webhooksEnabled) {
            DB::afterCommit(fn () => PublishWebhookEvent::dispatch($event->id)->onQueue('webhooks'));
        }

        if ($notificationsEnabled) {
            DB::afterCommit(fn () => PublishNotificationEvent::dispatch($event->id)->onQueue('notifications'));
        }

        return $event;
    }
}
