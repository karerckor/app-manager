<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'team_id',
    'integration_id',
    'url',
    'secret',
    'secret_hint',
    'subscribed_events',
    'consecutive_failures',
    'last_delivered_at',
])]
class WebhookEndpoint extends Model implements Auditable
{
    use HasFactory, HasUuids, SoftDeletes, AuditableTrait;

    protected array $auditExclude = ['secret'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->subscribed_events, true);
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'subscribed_events' => 'array',
            'last_delivered_at' => 'datetime',
        ];
    }
}
