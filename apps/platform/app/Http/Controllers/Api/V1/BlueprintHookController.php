<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlueprintHookRequest;
use App\Http\Requests\UpdateBlueprintHookRequest;
use App\Http\Resources\Api\V1\BlueprintHookResource;
use App\Models\Blueprint;
use App\Models\BlueprintHook;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BlueprintHookController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly BlueprintComplianceEvaluator $evaluator) {}

    public function index(Request $request, Team $team, Blueprint $blueprint): AnonymousResourceCollection
    {
        return BlueprintHookResource::collection(
            $blueprint->hooks()->orderBy('created_at')->paginate($this->perPage($request)),
        );
    }

    public function store(StoreBlueprintHookRequest $request, Team $team, Blueprint $blueprint): JsonResponse
    {
        $hook = $blueprint->hooks()->create($request->blueprintHookData());
        $this->evaluate($blueprint);

        return (new BlueprintHookResource($hook))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team, Blueprint $blueprint, BlueprintHook $hook): BlueprintHookResource
    {
        return new BlueprintHookResource($hook);
    }

    public function update(UpdateBlueprintHookRequest $request, Team $team, Blueprint $blueprint, BlueprintHook $hook): BlueprintHookResource
    {
        $hook->update($request->blueprintHookData());
        $this->evaluate($blueprint);

        return new BlueprintHookResource($hook->refresh());
    }

    public function destroy(Team $team, Blueprint $blueprint, BlueprintHook $hook): Response
    {
        $hook->delete();

        return response()->noContent();
    }

    private function evaluate(Blueprint $blueprint): void
    {
        $blueprint->mobileApps()->each(fn (MobileApp $app) => $this->evaluator->evaluate($app));
    }
}
