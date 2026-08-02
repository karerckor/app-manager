<?php

namespace App\Integrations\Notifications;

use App\Enums\IntegrationProvider;
use App\Models\NotificationDestination;
use Throwable;

class NotificationSender
{
    public function __construct(
        private readonly SlackNotificationProvider $slack,
        private readonly DiscordNotificationProvider $discord,
        private readonly TelegramNotificationProvider $telegram,
    ) {}

    public function send(NotificationDestination $destination, string $message): void
    {
        $provider = $destination->integration?->provider;

        try {
            match ($provider) {
                IntegrationProvider::Slack => $this->slack->send($destination, $message),
                IntegrationProvider::Discord => $this->discord->send($destination, $message),
                IntegrationProvider::Telegram => $this->telegram->send($destination, $message),
                default => throw new NotificationDeliveryException('Choose a supported notification provider.'),
            };
        } catch (NotificationDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new NotificationDeliveryException(
                ($provider?->label() ?? 'Notification provider').' could not deliver the message.',
            );
        }
    }
}
