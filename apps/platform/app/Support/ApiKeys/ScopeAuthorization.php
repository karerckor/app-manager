<?php

namespace App\Support\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ScopeAuthorization
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $callerAbilities  Abilities of the token making the request, or null for a web session.
     */
    public function assertAssignable(User $creator, Team $team, array $scopes, ?array $callerAbilities): void
    {
        $isOwner = $creator->id === $team->owner_id;
        $callerHasWildcard = $callerAbilities !== null && in_array('*', $callerAbilities, true);

        foreach ($scopes as $scope) {
            $enum = ApiKeyScope::tryFrom($scope);

            if ($enum === null) {
                $this->deny('Unknown scope: '.$scope);
            }

            $permission = $enum->requiredPermission();

            // Owner-only scopes (requiredPermission() === null) require team ownership.
            if ($permission === null) {
                if (! $isOwner) {
                    $this->deny('You may not grant the scope: '.$scope);
                }
            } elseif (! $team->allows($creator, $permission)) {
                $this->deny('Your role may not grant the scope: '.$scope);
            }

            // A calling token cannot mint a scope it does not itself hold.
            if ($callerAbilities !== null && ! $callerHasWildcard && ! in_array($scope, $callerAbilities, true)) {
                $this->deny('The current token cannot grant the scope: '.$scope);
            }
        }
    }

    private function deny(string $message): never
    {
        throw ValidationException::withMessages(['scopes' => $message]);
    }
}
