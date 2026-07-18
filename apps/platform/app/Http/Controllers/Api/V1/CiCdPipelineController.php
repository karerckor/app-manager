<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CiCdPipelineResource;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CiCdPipelineController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team, Integration $integration): AnonymousResourceCollection
    {
        $this->assertIntegration($team, $integration);

        return CiCdPipelineResource::collection(
            $integration->pipelines()->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, Integration $integration, CiCdPipeline $pipeline): CiCdPipelineResource
    {
        $this->assertPipeline($team, $integration, $pipeline);

        return new CiCdPipelineResource($pipeline);
    }

    protected function assertIntegration(Team $team, Integration $integration): void
    {
        abort_unless($integration->team_id === $team->id, 404);
    }

    protected function assertPipeline(Team $team, Integration $integration, CiCdPipeline $pipeline): void
    {
        $this->assertIntegration($team, $integration);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);
    }
}
