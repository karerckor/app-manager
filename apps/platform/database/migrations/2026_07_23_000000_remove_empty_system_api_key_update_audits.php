<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('audits')
            ->where('auditable_type', 'App\\Models\\ApiKey')
            ->where('event', 'updated')
            ->where('source', 'system')
            ->whereNull('user_id')
            ->select(['id', 'old_values', 'new_values'])
            ->orderBy('id')
            ->chunkById(500, function ($audits): void {
                foreach ($audits as $audit) {
                    $oldValues = json_decode($audit->old_values, true, flags: JSON_THROW_ON_ERROR);
                    $newValues = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);

                    if ($oldValues !== [] || $newValues !== []) {
                        continue;
                    }

                    DB::table('audits')->where('id', $audit->id)->delete();
                }
            }, 'id');
    }

    public function down(): void
    {
        // Deleted audit noise cannot be reconstructed safely.
    }
};
