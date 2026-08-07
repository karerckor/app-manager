<?php

namespace App\Http\Controllers;

use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLogDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BuildLogDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLogDownload $downloads,
    ): Response {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless(
            $mobileApp->team_id === $team->id
            && $buildRecord->team_id === $team->id
            && $buildRecord->mobile_app_id === $mobileApp->id,
            404,
        );

        return $downloads->download($buildRecord);
    }
}
