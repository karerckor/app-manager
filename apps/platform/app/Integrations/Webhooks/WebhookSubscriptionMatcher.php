<?php

namespace App\Integrations\Webhooks;

use App\Enums\WebhookEventType;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;

class WebhookSubscriptionMatcher
{
    public function matches(WebhookEndpoint $endpoint, WebhookEvent $event): bool
    {
        if ($event->event_type === WebhookEventType::WebhookTest) {
            return ($event->payload['endpoint_id'] ?? null) === $endpoint->id;
        }

        if (! $endpoint->subscribesTo($event->event_type->value)) {
            return false;
        }

        $filters = WebhookSubscriptionFilters::normalize($endpoint->filters);
        $context = $event->filter_context ?? [];

        return $this->intersects(
            $filters['mobile_app_ids'],
            $context['mobile_app_ids'] ?? [],
        ) && $this->intersects(
            array_map('mb_strtolower', $filters['tags']),
            $context['tags'] ?? [],
        ) && $this->contains(
            $filters['build_statuses'],
            $context['build_status'] ?? null,
        ) && $this->contains(
            $filters['profile_types'],
            $context['profile_type'] ?? null,
        );
    }

    /**
     * @param  list<string>  $filter
     */
    private function intersects(array $filter, mixed $context): bool
    {
        if ($filter === []) {
            return true;
        }

        return is_array($context) && array_intersect($filter, $context) !== [];
    }

    /**
     * @param  list<string>  $filter
     */
    private function contains(array $filter, mixed $value): bool
    {
        return $filter === [] || (is_string($value) && in_array($value, $filter, true));
    }
}
