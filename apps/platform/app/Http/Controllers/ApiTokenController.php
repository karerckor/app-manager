<?php

namespace App\Http\Controllers;

use App\Support\ApiTokenAbilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiTokenAbilities::all())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $abilities = ApiTokenAbilities::normalize($data['abilities'] ?? ApiTokenAbilities::build());
        $token = $request->user()->createToken(
            $data['name'],
            $abilities,
            isset($data['expires_at']) ? now()->parse($data['expires_at']) : null,
        );

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
