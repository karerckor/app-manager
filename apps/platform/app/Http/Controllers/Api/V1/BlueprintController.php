<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlueprintRequest;
use App\Http\Requests\UpdateBlueprintRequest;
use App\Http\Resources\Api\V1\BlueprintResource;
use App\Models\Blueprint;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BlueprintController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly BlueprintComplianceEvaluator $evaluator) {}

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return BlueprintResource::collection(
            $team->blueprints()
                ->withCount(['mobileApps', 'hooks'])
                ->orderBy('name')
                ->paginate($this->perPage($request)),
        );
    }

    public function store(StoreBlueprintRequest $request, Team $team): JsonResponse
    {
        $blueprint = $team->blueprints()->create($request->blueprintData());

        return (new BlueprintResource($blueprint->loadCount(['mobileApps', 'hooks'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team, Blueprint $blueprint): BlueprintResource
    {
        return new BlueprintResource($this->load($blueprint));
    }

    public function update(UpdateBlueprintRequest $request, Team $team, Blueprint $blueprint): BlueprintResource
    {
        $blueprint->update($request->blueprintData());
        $this->evaluateApplications($blueprint);

        return new BlueprintResource($this->load($blueprint->refresh()));
    }

    public function destroy(Team $team, Blueprint $blueprint): Response
    {
        $blueprint->delete();

        return response()->noContent();
    }

    public function restore(Team $team, string $blueprint): BlueprintResource
    {
        $blueprintModel = $team->blueprints()->onlyTrashed()->whereKey($blueprint)->firstOrFail();
        $blueprintModel->restore();

        return new BlueprintResource($this->load($blueprintModel->refresh()));
    }

    public function evaluate(Team $team, Blueprint $blueprint): JsonResponse
    {
        $evaluated = $this->evaluateApplications($blueprint);

        return response()->json(['data' => ['evaluated_applications' => $evaluated]]);
    }

    private function load(Blueprint $blueprint): Blueprint
    {
        return $blueprint
            ->load(['hooks' => fn ($query) => $query->orderBy('created_at')])
            ->loadCount(['mobileApps', 'hooks']);
    }

    private function evaluateApplications(Blueprint $blueprint): int
    {
        $evaluated = 0;

        $blueprint->mobileApps()->each(function (MobileApp $app) use (&$evaluated): void {
            $this->evaluator->evaluate($app);
            $evaluated++;
        });

        return $evaluated;
    }
}
