<?php

namespace App\Models;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Models\Concerns\HasTags;
use App\Support\Encryption\Casts\EncryptedArrayWithDEK;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'type', 'storage_mode', 'name', 'payload', 'expires_at', 'tags', 'fingerprint'])]
class Credential extends Model implements Auditable, HasMedia
{
    use AuditableTrait, HasFactory, HasTags, HasUuids, InteractsWithMedia, SoftDeletes;

    protected array $auditExclude = ['payload', 'fingerprint'];

    // Mirrors the migration's `->default('local')` (NOT NULL). Credential
    // uses HasUuids, so create() never round-trips the inserted row and the
    // in-memory model would otherwise see this enum as null until refreshed.
    protected $attributes = [
        'storage_mode' => CredentialStorageMode::Local->value,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function appIdentifiers(): MorphToMany
    {
        return $this->morphedByMany(AppIdentifier::class, 'credentialable')->withTimestamps();
    }

    public function profiles(): MorphToMany
    {
        return $this->morphedByMany(Profile::class, 'credentialable')->withTimestamps();
    }

    public function expoCredentialLinks(): HasMany
    {
        return $this->hasMany(ExpoCredentialLink::class);
    }

    public function vaultReference(): HasOne
    {
        return $this->hasOne(CredentialVaultReference::class);
    }

    public function isExternal(): bool
    {
        return $this->storage_mode === CredentialStorageMode::External;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'storage_mode' => CredentialStorageMode::class,
            'payload' => EncryptedArrayWithDEK::class,
            'expires_at' => 'datetime',
        ];
    }
}
