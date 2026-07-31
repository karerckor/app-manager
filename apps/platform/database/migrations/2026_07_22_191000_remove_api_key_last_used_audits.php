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
            ->select(['id', 'old_values', 'new_values'])
            ->orderBy('id')
            ->chunkById(500, function ($audits): void {
                foreach ($audits as $audit) {
                    $oldValues = json_decode($audit->old_values, true, flags: JSON_THROW_ON_ERROR);
                    $newValues = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);

                    if (count($oldValues) !== 1
                        || count($newValues) !== 1
                        || ! array_key_exists('last_used_at', $oldValues)
                        || ! array_key_exists('last_used_at', $newValues)) {
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
