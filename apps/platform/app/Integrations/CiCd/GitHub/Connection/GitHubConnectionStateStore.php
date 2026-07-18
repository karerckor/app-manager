<?php

namespace App\Integrations\CiCd\GitHub\Connection;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GitHubConnectionStateStore
{
    public function issue(User $user, Team $team): string
    {
        $state = Str::random(64);
        Cache::put($this->key($state), [
            'user_id' => $user->id,
            'team_id' => $team->id,
        ], now()->addSeconds((int) config('integrations.github.connection_state_ttl_seconds', 600)));

        return $state;
    }

    /** @return array{user_id: string, team_id: string}|null */
    public function consume(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $value = Cache::pull($this->key($state));

        return is_array($value) ? $value : null;
    }

    private function key(string $state): string
    {
        return 'github-connection-state:'.hash('sha256', $state);
    }
}
