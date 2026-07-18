<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\StartPipelineBuild;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineBuildController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
        StartPipelineBuild $start,
    ): JsonResponse {
        abort_unless($integration->team_id === $team->id, 404);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);

        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'ref' => ['nullable', 'string', 'max:255'],
            'inputs' => ['sometimes', 'array', 'max:25'],
        ]);

        $build = $start->handle(
            $team,
            $integration,
            $pipeline,
            $request->user(),
            $data['request_uuid'],
            $data['ref'] ?? null,
            $data['inputs'] ?? [],
        );

        return (new BuildRecordResource($build->load('ciCdRun')))->response()->setStatusCode(201);
    }
}
