<?php

namespace App\Integrations\CiCd\Connection;

use App\Enums\IntegrationProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConnectionStateStore
{
    public function issue(User $user, Team $team, IntegrationProvider $provider): string
    {
        $state = Str::random(64);
        Cache::put($this->key($state), [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'provider' => $provider->value,
        ], now()->addSeconds((int) config("integrations.{$provider->value}.connection_state_ttl_seconds", 600)));

        return $state;
    }

    /** @return array{user_id: string, team_id: string, provider: string}|null */
    public function consume(string $state, IntegrationProvider $provider): ?array
    {
        $context = Cache::pull($this->key($state));

        return is_array($context) && ($context['provider'] ?? null) === $provider->value
            ? $context
            : null;
    }

    private function key(string $state): string
    {
        return 'ci-cd-connection-state:'.hash('sha256', $state);
    }
}
