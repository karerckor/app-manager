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
    public function show(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): JsonResponse
    {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        $output = $buildRecord->log_output ?? '';

        if (! $request->has('offset') && ! $request->has('limit')) {
            return response()->json(['data' => [
                'build_id' => $buildRecord->id,
                'content' => $output,
                'updated_at' => $buildRecord->logs_updated_at,
                'archived_at' => $buildRecord->logs_archived_at,
                'archive_url' => $this->archiveUrl($team, $mobileApp, $buildRecord),
            ]]);
        }

        $total = strlen($output);
        $offset = max(0, $request->integer('offset', 0));
        $limit = $request->integer('limit', BuildLog::MAX_CHUNK_BYTES);
        $limit = $limit < 1 ? BuildLog::MAX_CHUNK_BYTES : min($limit, BuildLog::MAX_CHUNK_BYTES);
        $slice = $offset >= $total ? '' : substr($output, $offset, $limit);
        $nextOffset = $offset + strlen($slice);

        return response()->json(['data' => [
            'build_id' => $buildRecord->id,
            'content' => $slice,
            'updated_at' => $buildRecord->logs_updated_at,
            'archived_at' => $buildRecord->logs_archived_at,
            'archive_url' => $this->archiveUrl($team, $mobileApp, $buildRecord),
            'meta' => [
                'offset' => $offset,
                'next_offset' => $nextOffset < $total ? $nextOffset : null,
                'returned_bytes' => strlen($slice),
                'total_bytes' => $total,
            ],
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

    private function archiveUrl(Team $team, MobileApp $mobileApp, BuildRecord $build): ?string
    {
        return $build->log_archive_path
            ? route('api.v1.apps.builds.logs.archive', [$team, $mobileApp, $build])
            : null;
    }
}
