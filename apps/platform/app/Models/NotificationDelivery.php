<?php

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id',
    'webhook_event_id',
    'notification_destination_id',
    'status',
    'attempt_count',
    'rendered_message',
    'next_attempt_at',
    'last_error',
    'delivered_at',
    'exhausted_at',
])]
class NotificationDelivery extends Model
{
    use HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(NotificationDestination::class, 'notification_destination_id');
    }

    protected function casts(): array
    {
        return [
            'status' => NotificationDeliveryStatus::class,
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'exhausted_at' => 'datetime',
        ];
    }
}
