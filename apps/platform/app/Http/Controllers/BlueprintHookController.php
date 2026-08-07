<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlueprintHookRequest;
use App\Http\Requests\UpdateBlueprintHookRequest;
use App\Models\Blueprint;
use App\Models\BlueprintHook;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlueprintHookController extends Controller
{
    public function __construct(private readonly BlueprintComplianceEvaluator $evaluator) {}

    public function store(StoreBlueprintHookRequest $request, Team $team, Blueprint $blueprint): RedirectResponse
    {
        $blueprint->hooks()->create($request->blueprintHookData());
        $this->evaluate($blueprint);

        return back()->with('status', 'Conditional hook created and evaluated.');
    }

    public function update(UpdateBlueprintHookRequest $request, Team $team, Blueprint $blueprint, BlueprintHook $hook): RedirectResponse
    {
        $hook->update($request->blueprintHookData());
        $this->evaluate($blueprint);

        return back()->with('status', 'Conditional hook updated and evaluated.');
    }

    public function destroy(Request $request, Team $team, Blueprint $blueprint, BlueprintHook $hook): RedirectResponse
    {
        $hook->delete();

        return back()->with('status', 'Conditional hook deleted. Existing tags were left unchanged.');
    }

    private function evaluate(Blueprint $blueprint): void
    {
        $blueprint->mobileApps()->each(fn (MobileApp $app) => $this->evaluator->evaluate($app));
    }
}
