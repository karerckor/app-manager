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
    'destination',
    'credentials',
    'subscribed_events',
    'templates',
    'tags',
    'consecutive_failures',
    'last_delivered_at',
])]
class NotificationDestination extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected array $auditExclude = ['credentials'];

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
        return $this->hasMany(NotificationDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'subscribed_events' => 'array',
            'templates' => 'array',
            'tags' => 'array',
            'last_delivered_at' => 'datetime',
        ];
    }
}
