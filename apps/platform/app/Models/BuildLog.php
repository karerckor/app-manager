<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'build_record_id',
    'sequence',
    'stream',
    'level',
    'message',
    'logged_at',
])]
class BuildLog extends Model
{
    use HasFactory;

    public function buildRecord(): BelongsTo
    {
        return $this->belongsTo(BuildRecord::class);
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'logged_at' => 'datetime',
        ];
    }
}
