<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BuildLogController extends Controller
{
    public function index(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
    ): JsonResponse {
        $this->authorize('view', $team);

        $data = $request->validate([
            'after_sequence' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $limit = $data['limit'] ?? 200;
        $logs = $buildRecord->logs()
            ->when(
                isset($data['after_sequence']),
                fn ($query) => $query->where('sequence', '>', $data['after_sequence']),
            )
            ->orderBy('sequence')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $logs->map(fn ($log) => [
                'sequence' => $log->sequence,
                'stream' => $log->stream,
                'level' => $log->level,
                'message' => $log->message,
                'logged_at' => $log->logged_at ?? $log->created_at,
            ]),
            'meta' => [
                'next_sequence' => $logs->last()?->sequence,
                'has_more' => $logs->count() === $limit
                    && $buildRecord->logs()->where('sequence', '>', $logs->last()->sequence)->exists(),
            ],
        ]);
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
    ): JsonResponse {
        $this->authorize('manageCredentials', $team);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.sequence' => ['required', 'integer', 'min:0', 'distinct'],
            'entries.*.stream' => ['nullable', Rule::in(['stdout', 'stderr', 'system'])],
            'entries.*.level' => ['nullable', Rule::in(['debug', 'info', 'warning', 'error'])],
            'entries.*.message' => ['required', 'string', 'max:65535'],
            'entries.*.logged_at' => ['nullable', 'date'],
        ]);
        $now = now();
        $rows = collect($data['entries'])->map(fn ($entry) => [
            'build_record_id' => $buildRecord->id,
            'sequence' => $entry['sequence'],
            'stream' => $entry['stream'] ?? 'stdout',
            'level' => $entry['level'] ?? (($entry['stream'] ?? null) === 'stderr' ? 'error' : 'info'),
            'message' => $entry['message'],
            'logged_at' => $entry['logged_at'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        $accepted = DB::table('build_logs')->insertOrIgnore($rows);

        return response()->json([
            'data' => [
                'accepted' => $accepted,
                'last_sequence' => $buildRecord->logs()->max('sequence'),
            ],
        ], 202);
    }
}
