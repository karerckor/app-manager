<?php

namespace App\Http\Controllers;

use App\Enums\ApiKeyScope;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamApiKeyController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeManagement($request, $team);

        $apiKeys = $team->apiKeys()
            ->with('tokenable:id,name,email')
            ->latest()
            ->get()
            ->map(fn ($apiKey) => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'scopes' => $apiKey->abilities ?? [],
                'created_by' => $apiKey->tokenable?->only('id', 'name', 'email'),
                'last_used_at' => $apiKey->last_used_at,
                'created_at' => $apiKey->created_at,
                'revoke_url' => route('accounts.api-keys.destroy', [
                    'team' => $team,
                    'apiKey' => $apiKey,
                ]),
            ]);

        return Inertia::render('ApiKeys/Index', [
            'apiKeys' => $apiKeys,
            'scopeGroups' => ApiKeyScope::groups(),
            'createUrl' => route('accounts.api-keys.store', ['team' => $team]),
        ]);
    }

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeManagement($request, $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::enum(ApiKeyScope::class)],
        ]);

        $token = $request->user()->createTeamApiKey(
            $team,
            $data['name'],
            $data['scopes'],
        );

        return back()->with([
            'status' => 'API key created. Copy it now; it will not be shown again.',
            'token' => $token->plainTextToken,
        ]);
    }

    public function destroy(Request $request, Team $team, int $apiKey): RedirectResponse
    {
        $this->authorizeManagement($request, $team);

        $team->apiKeys()->whereKey($apiKey)->firstOrFail()->delete();

        return back()->with('status', 'API key revoked.');
    }

    private function authorizeManagement(Request $request, Team $team): void
    {
        abort_unless($request->user()?->id === $team->owner_id, 403);
    }
}
