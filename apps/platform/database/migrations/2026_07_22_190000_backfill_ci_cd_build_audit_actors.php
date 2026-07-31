<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('audits')
            ->where('auditable_type', 'App\\Models\\BuildRecord')
            ->where('event', 'updated')
            ->where('source', 'system')
            ->whereNull('user_id')
            ->select(['id', 'auditable_id'])
            ->orderBy('id')
            ->chunk(500, function ($audits): void {
                $requesters = DB::table('ci_cd_runs')
                    ->whereIn('build_record_id', $audits->pluck('auditable_id'))
                    ->whereNotNull('requested_by_user_id')
                    ->pluck('requested_by_user_id', 'build_record_id');

                foreach ($audits as $audit) {
                    $requester = $requesters->get($audit->auditable_id);

                    if ($requester === null) {
                        continue;
                    }

                    DB::table('audits')
                        ->where('id', $audit->id)
                        ->update([
                            'user_type' => 'App\\Models\\User',
                            'user_id' => $requester,
                            'source' => 'ci_cd',
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Corrected audit attribution cannot be distinguished from new records safely.
    }
};
