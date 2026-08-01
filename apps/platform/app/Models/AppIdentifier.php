<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable(['mobile_app_id', 'platform', 'identifier', 'name', 'version', 'version_code'])]
class AppIdentifier extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids;

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function buildRecords(): HasMany
    {
        return $this->hasMany(BuildRecord::class);
    }

    public function ciCdPipelines(): HasMany
    {
        return $this->hasMany(CiCdPipeline::class);
    }

    public function credentials(): MorphToMany
    {
        return $this->morphToMany(Credential::class, 'credentialable')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'version_code' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AppIdentifier $appIdentifier) {
            if ($appIdentifier->platform !== Platform::Android) {
                $appIdentifier->version_code = null;
            }
        });

        static::deleting(function (AppIdentifier $appIdentifier) {
            $appIdentifier->credentials()->detach();
            $appIdentifier->profiles->each->delete();
        });
    }
}
