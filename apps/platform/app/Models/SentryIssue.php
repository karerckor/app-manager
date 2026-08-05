<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'mobile_app_id',
    'app_identifier_id',
    'scope_key',
    'fingerprint',
    'grouping_version',
    'status',
    'level',
    'title',
    'events_count',
    'first_seen_at',
    'last_seen_at',
])]
class SentryIssue extends Model
{
    use HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SentryEvent::class);
    }

    protected function casts(): array
    {
        return [
            'events_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
