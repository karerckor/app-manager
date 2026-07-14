<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'current_team_id'),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'token' => fn () => $request->session()->get('token'),
            ],
        ];
    }
}
