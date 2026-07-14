<?php

namespace App\Models;

use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['app_identifier_id', 'type', 'name'])]
class Profile extends Model
{
    use HasFactory;

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    public function credentials(): MorphToMany
    {
        return $this->morphToMany(Credential::class, 'credentialable')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'type' => ProfileType::class,
        ];
    }
}
