<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'slug', 'name', 'description'])]
class MobileApp extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function appIdentifiers(): HasMany
    {
        return $this->hasMany(AppIdentifier::class);
    }

    public function buildRecords(): HasMany
    {
        return $this->hasMany(BuildRecord::class);
    }

    public function ciCdPipelines(): HasMany
    {
        return $this->hasMany(CiCdPipeline::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('app_icon')->singleFile();
        $this->addMediaCollection('splash_icon')->singleFile();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::deleting(function (MobileApp $mobileApp) {
            // Build records must be deleted via Eloquent (and thus purged) before the
            // app identifiers, whose deletion cascades build_records away at the DB
            // level (app_identifier_id has cascadeOnDelete), which would bypass the
            // BuildRecord::deleting hook and leave artifact media orphaned.
            $mobileApp->buildRecords()->cursor()->each(fn (BuildRecord $build) => $build->delete());
            $mobileApp->appIdentifiers->each->delete();
        });
    }
}
