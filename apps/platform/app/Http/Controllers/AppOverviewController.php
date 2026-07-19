<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppActivityFeed;
use App\Support\Apps\AppContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppOverviewController extends Controller
{
    public function __invoke(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $lastBuild = $mobileApp->buildRecords()->latest()->first();

        return Inertia::render('Apps/Overview', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'overview',
            'stats' => [
                'identifiers' => $mobileApp->appIdentifiers()->count(),
                'builds' => $mobileApp->buildRecords()->count(),
                'credentials' => $team->credentials()->count(),
                'last_build' => $lastBuild ? [
                    'status' => $lastBuild->status->value,
                    'at' => $lastBuild->finished_at ?? $lastBuild->created_at,
                ] : null,
            ],
            'activity' => AppActivityFeed::for($team, $mobileApp),
        ]);
    }
}
