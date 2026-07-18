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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->route('accounts.apps.show', [$team, $pipeline->mobileApp])
            ->with('status', 'Build queued.')
            ->with('build_id', $build->id);
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
}
