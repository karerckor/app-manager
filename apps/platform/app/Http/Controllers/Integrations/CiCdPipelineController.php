<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\SaveCiCdPipeline;
use App\Enums\BuildReportingMode;
use App\Enums\ProfileType;
use App\Http\Controllers\Controller;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data = $this->validated($request, true);
        $mobileAppIds = $data['mobile_app_ids'] ?? [$data['mobile_app_id']];
        unset($data['mobile_app_ids']);

        DB::transaction(function () use ($save, $team, $integration, $request, $data, $mobileAppIds) {
            foreach ($mobileAppIds as $mobileAppId) {
                $save->handle($team, $integration, $request->user(), [
                    ...$data,
                    'mobile_app_id' => $mobileAppId,
                    'app_identifier_id' => count($mobileAppIds) === 1
                        ? ($data['app_identifier_id'] ?? null)
                        : null,
                ]);
            }
        });

        $status = count($mobileAppIds) === 1
            ? 'Pipeline configured.'
            : 'Pipeline configured for '.count($mobileAppIds).' applications.';

        return back()->with('status', $status);
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

    public function renameBatch(
        Request $request,
        Team $team,
        Integration $integration,
    ): RedirectResponse {
        $data = $request->validate([
            'pipeline_ids' => ['required', 'array', 'min:1', 'max:50'],
            'pipeline_ids.*' => ['required', 'uuid', 'distinct'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $pipelines = $this->batchPipelines($team, $integration, $data['pipeline_ids']);

        foreach ($pipelines as $pipeline) {
            Gate::forUser($request->user())->authorize('update', $pipeline);
        }

        DB::transaction(function () use ($pipelines, $data, $request) {
            foreach ($pipelines as $pipeline) {
                $pipeline->update([
                    'name' => $data['name'],
                    'updated_by_user_id' => $request->user()->id,
                ]);
            }
        });

        return back()->with('status', $pipelines->count().' pipeline '.str('binding')->plural($pipelines->count()).' renamed.');
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

    public function destroyBatch(
        Request $request,
        Team $team,
        Integration $integration,
    ): RedirectResponse {
        $data = $request->validate([
            'pipeline_ids' => ['required', 'array', 'min:1', 'max:50'],
            'pipeline_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $pipelines = $this->batchPipelines($team, $integration, $data['pipeline_ids']);

        foreach ($pipelines as $pipeline) {
            Gate::forUser($request->user())->authorize('delete', $pipeline);
        }

        DB::transaction(function () use ($pipelines) {
            foreach ($pipelines as $pipeline) {
                $pipeline->delete();
            }
        });

        return back()->with('status', $pipelines->count().' pipeline '.str('binding')->plural($pipelines->count()).' removed. Historical builds were preserved.');
    }

    /** @param array<int, string> $pipelineIds */
    private function batchPipelines(Team $team, Integration $integration, array $pipelineIds): Collection
    {
        abort_unless($integration->team_id === $team->id, 404);
        $pipelines = CiCdPipeline::query()
            ->where('team_id', $team->id)
            ->where('integration_id', $integration->id)
            ->whereIn('id', $pipelineIds)
            ->get();
        abort_unless($pipelines->count() === count($pipelineIds), 404);

        return $pipelines;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $allowMultipleApps = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'app_identifier_id' => ['nullable', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'repository_full_name' => ['required', 'string', 'max:255'],
            'workflow_id' => ['required', 'string', 'max:255'],
            'default_ref' => ['required', 'string', 'max:255'],
            'input_defaults' => ['sometimes', 'array', 'max:25'],
            'enabled' => ['sometimes', 'boolean'],
            'reporting_mode' => ['sometimes', Rule::enum(BuildReportingMode::class)],
            'build_id_input' => ['nullable', 'string', 'max:255'],
        ];

        if ($allowMultipleApps) {
            $rules['mobile_app_id'] = ['required_without:mobile_app_ids', 'nullable', 'uuid'];
            $rules['mobile_app_ids'] = ['required_without:mobile_app_id', 'array', 'min:1', 'max:50'];
            $rules['mobile_app_ids.*'] = ['required', 'uuid', 'distinct'];
            $rules['app_identifier_id'][] = Rule::prohibitedIf(
                fn () => count((array) $request->input('mobile_app_ids', [])) > 1,
            );
        } else {
            $rules['mobile_app_id'] = ['required', 'uuid'];
        }

        return $request->validate($rules);
    }
}
