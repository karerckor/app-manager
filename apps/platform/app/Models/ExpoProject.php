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
    'import_status',
    'import_error',
    'import_started_at',
    'import_finished_at',
    'auto_sync_enabled',
    'auto_sync_status',
    'auto_sync_snapshot',
    'auto_sync_error',
    'auto_sync_pending_at',
    'auto_sync_started_at',
    'exists_on_expo',
    'last_synced_at',
    'last_pushed_at',
])]
class ExpoProject extends Model
{
    use HasUuids;

    protected $attributes = [
        'import_status' => 'idle',
        'auto_sync_enabled' => false,
        'auto_sync_status' => 'idle',
    ];

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
            'import_started_at' => 'datetime',
            'import_finished_at' => 'datetime',
            'auto_sync_enabled' => 'boolean',
            'auto_sync_snapshot' => 'array',
            'auto_sync_pending_at' => 'datetime',
            'auto_sync_started_at' => 'datetime',
            'exists_on_expo' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_pushed_at' => 'datetime',
        ];
    }
}
