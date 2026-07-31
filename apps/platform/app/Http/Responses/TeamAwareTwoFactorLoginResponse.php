<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class TeamAwareTwoFactorLoginResponse implements TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        if (! $request->user()->teams()->exists()) {
            return redirect()->route('accounts.create');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
