<?php

namespace App\Support\Audit;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ActorContextManager
{
    private ActorContext $current;

    public function __construct()
    {
        $this->current = ActorContext::system();
    }

    public function current(): ActorContext
    {
        return $this->current;
    }

    public function set(ActorContext $context): void
    {
        $this->current = $context;
    }

    public function runAs(ActorContext $context, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $context;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    public function fromRequest(Request $request): ActorContext
    {
        $user = $request->user();

        if ($user === null) {
            return ActorContext::system();
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $apiKey = $token instanceof ApiKey ? $token : ApiKey::find($token->getKey());

            return ActorContext::forUser($user, 'api', $apiKey, $request->ip(), $request->userAgent());
        }

        return ActorContext::forUser($user, 'web', null, $request->ip(), $request->userAgent());
    }
}
