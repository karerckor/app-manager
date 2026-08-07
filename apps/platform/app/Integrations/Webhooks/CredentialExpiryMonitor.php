<?php

namespace App\Integrations\Webhooks;

use App\Enums\WebhookEventType;
use App\Models\Credential;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;

final class CredentialExpiryMonitor
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function recordDue(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $recorded = 0;

        Credential::query()
            ->with('team', 'vaultReference')
            ->whereHas('team')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at->addDays(30))
            ->chunkById(100, function ($credentials) use ($at, &$recorded) {
                foreach ($credentials as $credential) {
                    $eventType = $this->eventType($credential, $at);

                    if (! $eventType) {
                        continue;
                    }

                    $payload = WebhookPayload::credential($credential);
                    $payload['days_remaining'] = $this->daysRemaining($credential, $at);

                    $alreadyRecorded = WebhookEvent::query()
                        ->where('team_id', $credential->team_id)
                        ->where('event_type', $eventType->value)
                        ->where('aggregate_type', class_basename($credential))
                        ->where('aggregate_id', $credential->id)
                        ->where('payload->expires_at', $payload['expires_at'])
                        ->exists();

                    if ($alreadyRecorded) {
                        continue;
                    }

                    if ($this->webhookEvents->record(
                        $credential->team,
                        $eventType,
                        $credential,
                        $payload,
                    )) {
                        $recorded++;
                    }
                }
            });

        return $recorded;
    }

    private function eventType(Credential $credential, CarbonImmutable $at): ?WebhookEventType
    {
        $expiresAt = $credential->expires_at?->toImmutable();

        if (! $expiresAt || $expiresAt->isAfter($at->addDays(30))) {
            return null;
        }

        if ($expiresAt->lessThanOrEqualTo($at)) {
            return WebhookEventType::CredentialExpired;
        }

        if ($expiresAt->lessThanOrEqualTo($at->addDay())) {
            return WebhookEventType::CredentialExpiringIn1Day;
        }

        if ($expiresAt->lessThanOrEqualTo($at->addDays(7))) {
            return WebhookEventType::CredentialExpiringIn7Days;
        }

        return WebhookEventType::CredentialExpiringIn30Days;
    }

    private function daysRemaining(Credential $credential, CarbonImmutable $at): int
    {
        $seconds = max(0, $credential->expires_at->getTimestamp() - $at->getTimestamp());

        return (int) ceil($seconds / 86400);
    }
}
