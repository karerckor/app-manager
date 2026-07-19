<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
