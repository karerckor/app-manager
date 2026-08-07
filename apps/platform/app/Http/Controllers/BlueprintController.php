<?php

namespace App\Http\Controllers;

use App\Enums\BlueprintHookAction;
use App\Enums\BlueprintHookCondition;
use App\Enums\Platform;
use App\Http\Requests\StoreBlueprintRequest;
use App\Http\Requests\UpdateBlueprintRequest;
use App\Models\Blueprint;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlueprintController extends Controller
{
    public function __construct(private readonly BlueprintComplianceEvaluator $evaluator) {}

    public function index(Request $request, Team $team): Response
    {
        $blueprints = $team->blueprints()
            ->with(['hooks' => fn ($query) => $query->orderBy('created_at')])
            ->withCount('mobileApps')
            ->orderBy('name')
            ->get()
            ->map(fn (Blueprint $blueprint) => $this->blueprintProp($team, $blueprint));

        $selectedBlueprintId = $request->string('blueprint')->toString();
        $selectedBlueprint = $blueprints->firstWhere('id', $selectedBlueprintId) ?? $blueprints->first();
        $applicationSearch = trim($request->string('apps_search')->toString());
        $applications = $selectedBlueprint
            ? $team->mobileApps()
                ->where('blueprint_id', $selectedBlueprint['id'])
                ->when($applicationSearch !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($applicationSearch) {
                    $pattern = "%{$applicationSearch}%";

                    $query->whereLike('name', $pattern)->orWhereLike('slug', $pattern);
                }))
                ->orderBy('name')
                ->paginate(25, ['*'], 'apps_page')
                ->withQueryString()
                ->through(fn (MobileApp $app) => $this->applicationProp($team, $app))
            : null;

        return Inertia::render('Blueprints/Index', [
            'active' => 'blueprints',
            'blueprints' => $blueprints,
            'selectedBlueprintId' => $selectedBlueprint['id'] ?? null,
            'applications' => $applications,
            'applicationFilters' => ['search' => $applicationSearch],
            'indexUrl' => route('accounts.blueprints.index', $team),
            'storeUrl' => route('accounts.blueprints.store', $team),
            'conditionOptions' => collect(BlueprintHookCondition::cases())->map(fn ($condition) => [
                'value' => $condition->value,
                'label' => $condition->label(),
                'config_key' => $condition->configKey(),
            ])->values(),
            'actionOptions' => collect(BlueprintHookAction::cases())->map(fn ($action) => [
                'value' => $action->value,
                'label' => $action->label(),
            ])->values(),
            'platformOptions' => collect(Platform::cases())->map(fn ($platform) => [
                'value' => $platform->value,
                'label' => strtoupper($platform->value),
            ])->values(),
            'tagSuggestions' => $team->mobileApps()->get(['tags'])
                ->flatMap(fn (MobileApp $app) => $app->tags ?? [])
                ->unique(fn (array $tag) => mb_strtolower($tag[0]))
                ->values(),
        ]);
    }

    public function store(StoreBlueprintRequest $request, Team $team): RedirectResponse
    {
        $team->blueprints()->create($request->blueprintData());

        return back()->with('status', 'Blueprint created.');
    }

    public function update(UpdateBlueprintRequest $request, Team $team, Blueprint $blueprint): RedirectResponse
    {
        $blueprint->update($request->blueprintData());
        $blueprint->mobileApps()->each(fn (MobileApp $app) => $this->evaluator->evaluate($app));

        return back()->with('status', 'Blueprint updated and attached applications checked.');
    }

    public function destroy(Request $request, Team $team, Blueprint $blueprint): RedirectResponse
    {
        $blueprint->delete();

        return back()->with('status', 'Blueprint moved to trash. Applications remain attached for recovery.');
    }

    public function restore(Request $request, Team $team, string $blueprint): RedirectResponse
    {
        $blueprintModel = $team->blueprints()->onlyTrashed()->whereKey($blueprint)->firstOrFail();
        $blueprintModel->restore();

        return back()->with('status', 'Blueprint restored.');
    }

    public function evaluate(Request $request, Team $team, Blueprint $blueprint): RedirectResponse
    {
        $blueprint->mobileApps()->each(fn (MobileApp $app) => $this->evaluator->evaluate($app));

        return back()->with('status', 'Blueprint hooks evaluated.');
    }

    private function blueprintProp(Team $team, Blueprint $blueprint): array
    {
        return [
            'id' => $blueprint->id,
            'name' => $blueprint->name,
            'description' => $blueprint->description,
            'json_schema' => $blueprint->json_schema,
            'required_files' => $blueprint->required_files ?? [],
            'platforms' => $blueprint->platforms ?? [],
            'requires_expo' => $blueprint->requires_expo,
            'apps_count' => $blueprint->mobile_apps_count,
            'hooks' => $blueprint->hooks->map(fn ($hook) => [
                'id' => $hook->id,
                'name' => $hook->name,
                'condition' => $hook->condition->value,
                'condition_config' => $hook->condition_config ?? [],
                'actions' => $hook->actions ?? [],
                'enabled' => $hook->enabled,
                'update_url' => route('accounts.blueprints.hooks.update', [$team, $blueprint, $hook]),
                'destroy_url' => route('accounts.blueprints.hooks.destroy', [$team, $blueprint, $hook]),
            ])->values(),
            'update_url' => route('accounts.blueprints.update', [$team, $blueprint]),
            'destroy_url' => route('accounts.blueprints.destroy', [$team, $blueprint]),
            'evaluate_url' => route('accounts.blueprints.evaluate', [$team, $blueprint]),
            'hooks_store_url' => route('accounts.blueprints.hooks.store', [$team, $blueprint]),
        ];
    }

    private function applicationProp(Team $team, MobileApp $app): array
    {
        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'url' => route('accounts.apps.show', [$team, $app]),
            'status' => $this->evaluator->status($app),
        ];
    }
}
