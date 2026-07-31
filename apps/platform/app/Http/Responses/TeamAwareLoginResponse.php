<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

class TeamAwareLoginResponse implements LoginResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        if (! $request->user()->teams()->exists()) {
            return redirect()->route('accounts.create');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
