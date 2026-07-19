<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\SaveCiCdPipeline;
use App\Enums\BuildReportingMode;
use App\Enums\ProfileType;
use App\Http\Controllers\Controller;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CiCdPipelineController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        Integration $integration,
        SaveCiCdPipeline $save,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $save->handle($team, $integration, $request->user(), $this->validated($request));

        return back()->with('status', 'Pipeline configured.');
    }

    public function update(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
        SaveCiCdPipeline $save,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);
        $save->handle($team, $integration, $request->user(), $this->validated($request), $pipeline);

        return back()->with('status', 'Pipeline updated.');
    }

    public function rename(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('update', $pipeline);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $pipeline->update([
            'name' => $data['name'],
            'updated_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Pipeline renamed.');
    }

    public function destroy(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('delete', $pipeline);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);
        $pipeline->delete();

        return back()->with('status', 'Pipeline removed. Historical builds were preserved.');
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
