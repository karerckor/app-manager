<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKey extends PersonalAccessToken
{
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
