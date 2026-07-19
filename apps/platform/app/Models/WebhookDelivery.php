<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id',
    'webhook_event_id',
    'webhook_endpoint_id',
    'redelivery_of_id',
    'status',
    'attempt_count',
    'payload_snapshot',
    'signature_version',
    'next_attempt_at',
    'response_status',
    'duration_ms',
    'response_body',
    'last_error',
    'delivered_at',
    'exhausted_at',
])]
class WebhookDelivery extends Model
{
    use HasFactory, HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id')->withTrashed();
    }

    public function redeliveryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redelivery_of_id');
    }

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'payload_snapshot' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'exhausted_at' => 'datetime',
        ];
    }
}
