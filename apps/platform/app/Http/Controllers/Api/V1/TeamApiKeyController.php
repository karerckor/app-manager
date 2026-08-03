<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiKeyScope;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\Team;
use App\Support\ApiKeys\ScopeAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TeamApiKeyController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            $team->apiKeys()->with('tokenable:id,name,email')->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, ApiKey $apiKey): ApiKeyResource
    {
        abort_unless($apiKey->team_id === $team->id, 404);

        return new ApiKeyResource($apiKey->load('tokenable:id,name,email'));
    }

    public function store(Request $request, Team $team, ScopeAuthorization $guard): JsonResponse
    {
        $this->authorizeOwner($request, $team);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(ApiKeyScope::assignableValues())],
        ]);

        $caller = $request->user()->currentAccessToken();
        $callerAbilities = $caller instanceof ApiKey ? ($caller->abilities ?? []) : null;
        $guard->assertAssignable($request->user(), $team, $data['scopes'], $callerAbilities);

        $token = $request->user()->createTeamApiKey($team, $data['name'], $data['scopes']);

        return response()->json([
            'data' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'scopes' => $token->accessToken->abilities ?? [],
                'token' => $token->plainTextToken,
                'created_at' => $token->accessToken->created_at,
            ],
        ], 201);
    }

    public function destroy(Request $request, Team $team, ApiKey $apiKey): Response
    {
        $this->authorizeOwner($request, $team);
        abort_unless($apiKey->team_id === $team->id, 404);
        $apiKey->delete();

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Team $team): void
    {
        abort_unless($request->user()?->id === $team->owner_id, 403);
    }
}
