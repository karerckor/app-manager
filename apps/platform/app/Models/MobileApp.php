<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use App\Support\Apps\AppMediaLibrary;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['team_id', 'slug', 'name', 'description', 'metadata', 'tags'])]
class MobileApp extends Model implements Auditable, HasMedia
{
    use AuditableTrait, HasFactory, HasTags, HasUuids, InteractsWithMedia;

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

    public function expoProject(): HasOne
    {
        return $this->hasOne(ExpoProject::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('app_icon')->singleFile();
        $this->addMediaCollection('splash_icon')->singleFile();
        $this->addMediaCollection('files');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (array_keys(AppMediaLibrary::assets()) as $collection) {
            foreach (AppMediaLibrary::variants($collection) as $variant) {
                $this->addMediaConversion($variant['name'])
                    ->performOnCollections($collection)
                    ->fit(Fit::Crop, $variant['width'], $variant['height'])
                    ->format('png')
                    ->queued();
            }
        }
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
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
