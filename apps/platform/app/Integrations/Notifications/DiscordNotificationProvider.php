<?php

namespace App\Integrations\Notifications;

use App\Integrations\Notifications\Contracts\NotificationProvider;
use App\Models\NotificationDestination;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class DiscordNotificationProvider implements NotificationProvider
{
    public function send(NotificationDestination $destination, string $message): void
    {
        if (mb_strlen($message) > 2000) {
            throw new InvalidArgumentException('Discord messages cannot exceed 2,000 characters.');
        }

        $url = data_get($destination->credentials, 'webhook_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Discord credentials are missing.');
        }

        $response = Http::asJson()
            ->withUserAgent('AppManager-Notifications/1.0')
            ->withQueryParameters(['wait' => 'true'])
            ->timeout((int) config('integrations.notifications.timeout_seconds'))
            ->connectTimeout((int) config('integrations.notifications.connect_timeout_seconds'))
            ->withoutRedirecting()
            ->post($url, [
                'content' => $message,
                'allowed_mentions' => ['parse' => []],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Discord rejected the notification.');
        }
    }
}
