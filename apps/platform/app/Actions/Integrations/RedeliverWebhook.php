<?php

namespace App\Actions\Integrations;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

class RedeliverWebhook
{
    public function handle(WebhookDelivery $source): WebhookDelivery
    {
        $delivery = DB::transaction(fn () => WebhookDelivery::create([
            'team_id' => $source->team_id,
            'webhook_event_id' => $source->webhook_event_id,
            'webhook_endpoint_id' => $source->webhook_endpoint_id,
            'redelivery_of_id' => $source->id,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
            'payload_snapshot' => $source->payload_snapshot,
        ]));

        DB::afterCommit(fn () => DeliverWebhook::dispatch($delivery->id)->onQueue('webhooks'));

        return $delivery;
    }
}
