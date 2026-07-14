<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'owner_id'])]
class Team extends Model
{
    use HasFactory, HasUuids;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function mobileApps(): HasMany
    {
        return $this->hasMany(MobileApp::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            $baseSlug = Str::slug($team->slug ?: $team->name) ?: 'account';
            $slug = $baseSlug;
            $suffix = 2;

            while (static::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $team->slug = $slug;
        });

        static::deleting(function (Team $team) {
            $team->mobileApps->each->delete();
            $team->credentials->each->delete();
        });
    }
}
