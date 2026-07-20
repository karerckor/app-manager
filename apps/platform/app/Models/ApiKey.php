<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKey extends PersonalAccessToken
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $table = 'personal_access_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
