<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuildReportingMode;
use App\Http\Controllers\Controller;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildLogController extends Controller
{
    public function show(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): JsonResponse
    {
        $this->assertBuild($team, $mobileApp, $buildRecord);

        return response()->json(['data' => [
            'build_id' => $buildRecord->id,
            'content' => $buildRecord->log_output ?? '',
            'updated_at' => $buildRecord->logs_updated_at,
        ]]);
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLog $logs,
    ): JsonResponse {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        abort_unless($buildRecord->reporting_mode === BuildReportingMode::Manual, 409, 'This build log is managed automatically.');
        $data = $request->validate([
            'content' => ['required', 'string'],
        ]);
        $build = $logs->append($buildRecord, $data['content']);

        return response()->json(['data' => [
            'build_id' => $build->id,
            'bytes' => strlen($build->log_output ?? ''),
            'updated_at' => $build->logs_updated_at,
        ]]);
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
