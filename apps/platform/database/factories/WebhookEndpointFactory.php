<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        $secret = 'whsec_'.Str::random(48);

        return [
            'url' => 'https://example.test/hook',
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'subscribed_events' => [WebhookEventType::subscribableValues()[0] ?? 'mobile_app.created'],
            'consecutive_failures' => 0,
        ];
    }
}
