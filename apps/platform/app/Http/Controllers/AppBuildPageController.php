<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\BuildPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppBuildPageController extends Controller
{
    public function index(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);

        $builds = $mobileApp->buildRecords()
            ->with(['ciCdRun.pipeline', 'artifacts.media'])
            ->latest()->limit(30)->get()
            ->map(fn ($build) => BuildPresenter::present($team, $mobileApp, $build, $canRun, $canManage));

        return Inertia::render('Apps/Builds/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'builds' => $builds,
        ]);
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);
        $buildRecord->load(['ciCdRun.pipeline', 'artifacts.media']);

        return Inertia::render('Apps/Builds/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'build' => BuildPresenter::present($team, $mobileApp, $buildRecord, $canRun, $canManage),
        ]);
    }

    private function permissions(Request $request, Team $team): array
    {
        return [
            $team->allows($request->user(), TeamPermission::RunPipelines),
            $team->allows($request->user(), TeamPermission::ManageApplications),
        ];
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
