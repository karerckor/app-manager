<?php

namespace App\Integrations\Notifications;

use App\Integrations\Notifications\Contracts\NotificationProvider;
use App\Models\NotificationDestination;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SlackNotificationProvider implements NotificationProvider
{
    public function send(NotificationDestination $destination, string $message): void
    {
        $url = data_get($destination->credentials, 'webhook_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Slack credentials are missing.');
        }

        $response = Http::asJson()
            ->withUserAgent('AppManager-Notifications/1.0')
            ->timeout((int) config('integrations.notifications.timeout_seconds'))
            ->connectTimeout((int) config('integrations.notifications.connect_timeout_seconds'))
            ->withoutRedirecting()
            ->post($url, ['text' => $message]);

        if ($response->failed()) {
            throw new RuntimeException('Slack rejected the notification.');
        }
    }
}
