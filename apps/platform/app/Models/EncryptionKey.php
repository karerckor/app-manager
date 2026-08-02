<?php

namespace App\Models;

use App\Enums\EncryptionKeyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['version', 'key_material', 'status'])]
class EncryptionKey extends Model
{
    use HasUuids;

    protected $table = 'encryption_keys';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => EncryptionKeyStatus::class,
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', EncryptionKeyStatus::Active);
    }

    public function scopeRetired($query)
    {
        return $query->where('status', EncryptionKeyStatus::Retired);
    }
}
