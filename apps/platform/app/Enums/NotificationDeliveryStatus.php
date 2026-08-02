<?php

namespace App\Enums;

enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case RetryScheduled = 'retry_scheduled';
    case Delivered = 'delivered';
    case Exhausted = 'exhausted';
}
