<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'build_record_id', 'name', 'metadata', 'tags'])]
class Artifact extends Model implements HasMedia
{
    use HasFactory, HasTags, HasUuids, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function buildRecord(): BelongsTo
    {
        return $this->belongsTo(BuildRecord::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
