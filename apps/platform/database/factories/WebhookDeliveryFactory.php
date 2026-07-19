<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
            'payload_snapshot' => ['type' => 'mobile_app.created'],
            'response_status' => null,
            'duration_ms' => null,
            'response_body' => null,
            'last_error' => null,
        ];
    }
}
