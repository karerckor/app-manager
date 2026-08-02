<?php

namespace App\Integrations\Notifications;

use App\Integrations\Notifications\Contracts\NotificationProvider;
use App\Models\NotificationDestination;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TelegramNotificationProvider implements NotificationProvider
{
    public function send(NotificationDestination $destination, string $message): void
    {
        if (mb_strlen($message) > 4096) {
            throw new InvalidArgumentException('Telegram messages cannot exceed 4,096 characters.');
        }

        $token = data_get($destination->credentials, 'bot_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram credentials are missing.');
        }

        $url = rtrim((string) config('integrations.notifications.telegram_api_url'), '/')
            .'/bot'.$token.'/sendMessage';
        $response = Http::asJson()
            ->withUserAgent('AppManager-Notifications/1.0')
            ->timeout((int) config('integrations.notifications.timeout_seconds'))
            ->connectTimeout((int) config('integrations.notifications.connect_timeout_seconds'))
            ->withoutRedirecting()
            ->post($url, [
                'chat_id' => $destination->destination,
                'text' => $message,
            ]);

        if ($response->failed() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram rejected the notification.');
        }
    }
}
