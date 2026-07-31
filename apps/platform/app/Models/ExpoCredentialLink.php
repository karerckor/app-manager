<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
    'expo_project_id',
    'credential_id',
    'app_identifier_id',
    'remote_resource_type',
    'remote_resource_id',
    'metadata',
    'last_synced_at',
])]
class ExpoCredentialLink extends Model
{
    use HasUuids;

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function expoProject(): BelongsTo
    {
        return $this->belongsTo(ExpoProject::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
