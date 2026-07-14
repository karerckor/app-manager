<?php

namespace App\Models;

use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_identifier_id', 'type', 'name'])]
class Profile extends Model
{
    use HasFactory;

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProfileType::class,
        ];
    }
}
