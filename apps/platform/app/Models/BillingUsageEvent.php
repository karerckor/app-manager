<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id',
    'event_key',
    'meter_event_name',
    'quantity',
    'occurred_at',
    'attempts',
    'next_attempt_at',
    'reported_at',
    'last_error',
])]
class BillingUsageEvent extends Model
{
    use HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
        ];
    }
}
