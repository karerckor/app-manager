<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'name', 'settings', 'installed_by_user_id', 'installed_at'])]
class Installation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'installed_at' => 'datetime',
        ];
    }
}
