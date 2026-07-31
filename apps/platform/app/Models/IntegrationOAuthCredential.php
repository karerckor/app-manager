<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
    'access_token',
    'refresh_token',
    'token_type',
    'scopes',
    'expires_at',
])]
class IntegrationOAuthCredential extends Model
{
    use HasUuids;

    protected $table = 'integration_oauth_credentials';

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
