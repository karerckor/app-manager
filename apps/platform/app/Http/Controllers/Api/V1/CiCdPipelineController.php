<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\SaveCiCdPipeline;
use App\Enums\BuildReportingMode;
use App\Enums\ProfileType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CiCdPipelineResource;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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

    public function store(Request $request, Team $team, Integration $integration, SaveCiCdPipeline $save): JsonResponse
    {
        $this->assertIntegration($team, $integration);
        $pipeline = $save->handle($team, $integration, $request->user(), $this->validated($request));

        return (new CiCdPipelineResource($pipeline))->response()->setStatusCode(201);
    }

    public function update(Request $request, Team $team, Integration $integration, CiCdPipeline $pipeline, SaveCiCdPipeline $save): CiCdPipelineResource
    {
        $this->assertPipeline($team, $integration, $pipeline);
        $pipeline = $save->handle($team, $integration, $request->user(), $this->validated($request), $pipeline);

        return new CiCdPipelineResource($pipeline);
    }

    public function destroy(Team $team, Integration $integration, CiCdPipeline $pipeline): Response
    {
        $this->assertPipeline($team, $integration, $pipeline);
        $pipeline->delete();

        return response()->noContent();
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

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile_app_id' => ['required', 'uuid'],
            'app_identifier_id' => ['nullable', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'repository_full_name' => ['required', 'string', 'max:255'],
            'workflow_id' => ['required', 'string', 'max:255'],
            'default_ref' => ['required', 'string', 'max:255'],
            'input_defaults' => ['sometimes', 'array', 'max:25'],
            'enabled' => ['sometimes', 'boolean'],
            'reporting_mode' => ['sometimes', Rule::enum(BuildReportingMode::class)],
            'build_id_input' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
