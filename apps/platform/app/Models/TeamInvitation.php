<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class TeamInvitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'email',
        'role',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute(
            'team-invitations.accept',
            $this->expires_at,
            ['invitation' => $this],
        );
    }

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
