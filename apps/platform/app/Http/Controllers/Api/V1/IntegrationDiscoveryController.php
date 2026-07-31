<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationDiscoveryController extends Controller
{
    public function repositories(Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);

        return response()->json(['data' => $providers->for($integration->provider)->repositories($integration)]);
    }

    public function workflows(Request $request, Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);
        $repository = $this->repository($request, $integration, $providers);

        return response()->json(['data' => $providers->for($integration->provider)->pipelines($integration, $repository)]);
    }

    public function refs(Request $request, Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);
        $repository = $this->repository($request, $integration, $providers);

        return response()->json(['data' => $providers->for($integration->provider)->refs($integration, $repository)]);
    }

    private function repository(Request $request, Integration $integration, CiCdProviderRegistry $providers): RepositoryData
    {
        $data = $request->validate(['repository' => ['required', 'string', 'max:255']]);

        return collect($providers->for($integration->provider)->repositories($integration))
            ->first(fn (RepositoryData $repository) => $repository->fullName === $data['repository'])
            ?? abort(404);
    }

    private function assertDispatchable(Team $team, Integration $integration): void
    {
        abort_unless(
            $integration->team_id === $team->id
            && $integration->supportsCiCd()
            && $integration->status->canDispatch(),
            404,
        );
    }
}
