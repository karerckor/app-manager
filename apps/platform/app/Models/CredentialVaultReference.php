<?php

namespace App\Models;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'credential_id',
    'team_id',
    'provider',
    'resource',
    'metadata',
    'metadata_version',
    'metadata_source',
    'verification_status',
    'verified_at',
    'verification_error',
])]
class CredentialVaultReference extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids;

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => VaultProvider::class,
            'metadata' => 'array',
            'metadata_version' => 'integer',
            'metadata_source' => VaultMetadataSource::class,
            'verification_status' => CredentialVerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}
