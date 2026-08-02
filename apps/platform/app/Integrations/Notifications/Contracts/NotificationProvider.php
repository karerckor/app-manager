<?php

namespace App\Integrations\Notifications\Contracts;

use App\Models\NotificationDestination;

interface NotificationProvider
{
    public function send(NotificationDestination $destination, string $message): void;
}
