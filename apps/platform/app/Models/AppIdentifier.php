<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['mobile_app_id', 'platform', 'identifier', 'name'])]
class AppIdentifier extends Model
{
    use HasFactory;

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function credentials(): MorphToMany
    {
        return $this->morphToMany(Credential::class, 'credentialable')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
        ];
    }
}
