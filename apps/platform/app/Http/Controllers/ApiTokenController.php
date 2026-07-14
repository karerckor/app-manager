<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $token = $request->user()->createToken($data['name'], ['*']);

        return back()->with([
            'status' => 'API key created. Copy it now; it will not be shown again.',
            'token' => $token->plainTextToken,
        ]);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return back()->with('status', 'API key revoked.');
    }
}
