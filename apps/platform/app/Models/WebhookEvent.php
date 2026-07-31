<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'event_type',
    'schema_version',
    'aggregate_type',
    'aggregate_id',
    'actor_user_id',
    'source',
    'payload',
    'filter_context',
    'occurred_at',
    'published_at',
])]
class WebhookEvent extends Model
{
    use HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'payload' => 'array',
            'filter_context' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
