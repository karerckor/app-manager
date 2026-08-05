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

#[Fillable(['mobile_app_id', 'platform', 'identifier', 'name', 'version', 'version_code', 'build_number', 'is_default'])]
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
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AppIdentifier $appIdentifier) {
            if (! $appIdentifier->exists
                && ! array_key_exists('is_default', $appIdentifier->getAttributes())) {
                $appIdentifier->is_default = ! self::query()
                    ->where('mobile_app_id', $appIdentifier->mobile_app_id)
                    ->where('platform', $appIdentifier->platform)
                    ->exists();
            }

            if ($appIdentifier->platform === Platform::Android) {
                $appIdentifier->build_number = null;
            } else {
                $appIdentifier->version_code = null;
            }

            if ($appIdentifier->platform === Platform::Web) {
                $appIdentifier->build_number = null;
            }

            if ($appIdentifier->is_default) {
                $siblings = self::query()
                    ->where('mobile_app_id', $appIdentifier->mobile_app_id)
                    ->where('platform', $appIdentifier->platform)
                    ->where('is_default', true);

                if ($appIdentifier->exists) {
                    $siblings->whereKeyNot($appIdentifier->id);
                }

                $siblings->update(['is_default' => false]);
            }
        });

        static::deleting(function (AppIdentifier $appIdentifier) {
            $appIdentifier->credentials()->detach();
            $appIdentifier->profiles->each->delete();
        });
    }
}
