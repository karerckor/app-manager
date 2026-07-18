<?php

namespace App\Http\Controllers;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Exceptions\InvalidBuildTransition;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ForceBuildStatusController extends Controller
{
    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLifecycle $lifecycle,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('runPipelines', $team);
        abort_unless(
            $mobileApp->team_id === $team->id
            && $buildRecord->team_id === $team->id
            && $buildRecord->mobile_app_id === $mobileApp->id,
            404,
        );
        abort_unless(
            $buildRecord->reporting_mode === BuildReportingMode::Manual,
            409,
            'Only manually reported builds can have their status forced.',
        );

        $data = $request->validate([
            'status' => ['required', Rule::in([
                BuildStatus::Completed->value,
                BuildStatus::Failed->value,
                BuildStatus::Cancelled->value,
            ])],
        ]);

        try {
            $build = $lifecycle->finish(
                $buildRecord,
                BuildStatus::from($data['status']),
                actor: $request->user(),
            );
        } catch (InvalidBuildTransition $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return back()->with('status', "Build status forced to {$build->status->value}.");
    }
}
