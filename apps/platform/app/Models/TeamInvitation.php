<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['team_id', 'invited_by', 'email', 'role', 'token', 'expires_at', 'accepted_at', 'revoked_at'])]
class TeamInvitation extends Model
{
    use HasUuids;

    public ?string $plainTextToken = null;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function getRouteKey(): mixed
    {
        return $this->plainTextToken ?? parent::getRouteKey();
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $field ??= $this->getRouteKeyName();
        $token = (string) $value;
        $invitation = $this->newQuery()
            ->where($field, hash('sha256', $token))
            ->first();

        if ($invitation) {
            $invitation->plainTextToken = $token;
        }

        return $invitation;
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TeamInvitation $invitation) {
            $invitation->plainTextToken ??= $invitation->token ?: Str::random(64);
            $invitation->token = hash('sha256', $invitation->plainTextToken);
        });
    }
}
