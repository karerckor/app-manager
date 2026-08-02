<?php

namespace App\Http\Controllers;

use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildLogArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLogArchive $archives,
    ): StreamedResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        $this->assertBuild($team, $mobileApp, $buildRecord);

        return $archives->download($buildRecord);
    }

    private function assertBuild(Team $team, MobileApp $mobileApp, BuildRecord $build): void
    {
        abort_unless(
            $mobileApp->team_id === $team->id
            && $build->team_id === $team->id
            && $build->mobile_app_id === $mobileApp->id,
            404,
        );
    }
}
