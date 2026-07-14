<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'type', 'name', 'payload', 'expires_at'])]
class Credential extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function appIdentifiers(): MorphToMany
    {
        return $this->morphedByMany(AppIdentifier::class, 'credentialable');
    }

    public function profiles(): MorphToMany
    {
        return $this->morphedByMany(Profile::class, 'credentialable');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }
}
