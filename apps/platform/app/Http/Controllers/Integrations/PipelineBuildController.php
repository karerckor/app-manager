<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\RequestPipelineBuildCancellation;
use App\Actions\Integrations\StartPipelineBuild;
use App\Http\Controllers\Controller;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PipelineBuildController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
        StartPipelineBuild $start,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('run', $pipeline);
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

        return redirect()
            ->route('accounts.apps.builds.index', [$team, $pipeline->mobileApp])
            ->with('status', 'Build queued.')
            ->with('build_id', $build->id);
    }

    public function storeBatch(
        Request $request,
        Team $team,
        Integration $integration,
        StartPipelineBuild $start,
    ): RedirectResponse {
        $data = $request->validate([
            'runs' => ['required', 'array', 'min:1', 'max:50'],
            'runs.*.pipeline_id' => ['required', 'uuid', 'distinct'],
            'runs.*.request_uuid' => ['required', 'uuid', 'distinct'],
            'runs.*.ref' => ['nullable', 'string', 'max:255'],
            'runs.*.inputs' => ['sometimes', 'array', 'max:25'],
        ]);
        $pipelineIds = collect($data['runs'])->pluck('pipeline_id')->all();
        $pipelines = $this->batchPipelines($team, $integration, $pipelineIds);

        foreach ($pipelines as $pipeline) {
            Gate::forUser($request->user())->authorize('run', $pipeline);
        }

        $builds = DB::transaction(function () use ($data, $pipelines, $start, $team, $integration, $request) {
            return collect($data['runs'])->map(function (array $run) use ($pipelines, $start, $team, $integration, $request) {
                $pipeline = $pipelines->firstWhere('id', $run['pipeline_id']);

                return $start->handle(
                    $team,
                    $integration,
                    $pipeline,
                    $request->user(),
                    $run['request_uuid'],
                    $run['ref'] ?? null,
                    $run['inputs'] ?? [],
                );
            });
        });

        $status = $builds->count() === 1 ? 'Build queued.' : $builds->count().' builds queued.';

        return back()
            ->with('status', $status)
            ->with('build_ids', $builds->pluck('id')->all());
    }

    public function cancel(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        RequestPipelineBuildCancellation $cancel,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('runPipelines', $team);
        abort_unless(
            $buildRecord->team_id === $team->id && $buildRecord->mobile_app_id === $mobileApp->id,
            404,
        );
        $cancel->handle($buildRecord);

        return back()->with('status', 'Build cancellation requested.');
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
}
