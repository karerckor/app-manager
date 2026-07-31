<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'integration_id',
    'mobile_app_id',
    'external_id',
    'account_id',
    'account_name',
    'slug',
    'name',
    'full_name',
    'description',
    'icon_url',
    'sync_metadata',
    'exists_on_expo',
    'last_synced_at',
])]
class ExpoProject extends Model
{
    use HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function credentialLinks(): HasMany
    {
        return $this->hasMany(ExpoCredentialLink::class);
    }

    protected function casts(): array
    {
        return [
            'sync_metadata' => 'boolean',
            'exists_on_expo' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
