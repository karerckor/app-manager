<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IntegrationResource;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntegrationController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return IntegrationResource::collection(
            $team->integrations()->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, Integration $integration): IntegrationResource
    {
        abort_unless($integration->team_id === $team->id, 404);

        return new IntegrationResource($integration);
    }
}
