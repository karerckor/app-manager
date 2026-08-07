<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blueprint_hooks')) {
            return;
        }

        if (Schema::hasColumn('blueprint_hooks', 'actions')) {
            return;
        }

        if (! Schema::hasColumn('blueprint_hooks', 'tags')) {
            Schema::table('blueprint_hooks', function (Blueprint $table) {
                // Nullable only for the compatibility transition. Fresh installs
                // create this column as non-null in the original table migration.
                $table->json('tags')->nullable()->after('action');
            });
        }

        if (! Schema::hasColumn('blueprint_hooks', 'tag')) {
            return;
        }

        $columns = ['id', 'tag'];

        foreach (['tag_color', 'tag_animation'] as $column) {
            if (Schema::hasColumn('blueprint_hooks', $column)) {
                $columns[] = $column;
            }
        }

        DB::table('blueprint_hooks')
            ->whereNull('tags')
            ->orderBy('id')
            ->get($columns)
            ->each(function (object $hook): void {
                $label = trim((string) ($hook->tag ?? ''));
                $color = filled($hook->tag_color ?? null)
                    ? strtolower((string) $hook->tag_color)
                    : null;
                $animation = filled($hook->tag_animation ?? null)
                    ? strtolower((string) $hook->tag_animation)
                    : null;

                $tuple = match (true) {
                    $animation !== null => [$label, $color, $animation],
                    $color !== null => [$label, $color],
                    default => [$label],
                };

                DB::table('blueprint_hooks')->where('id', $hook->id)->update([
                    'tags' => json_encode([$tuple], JSON_THROW_ON_ERROR),
                ]);
            });
    }

    public function down(): void
    {
        // The legacy scalar columns are deliberately retained by up(), so a
        // rollback only removes the compatibility column when those columns
        // prove this was an upgraded installation. On fresh installations the
        // canonical table migration owns `tags` and this must remain a no-op.
        if (Schema::hasTable('blueprint_hooks')
            && Schema::hasColumn('blueprint_hooks', 'tag')
            && Schema::hasColumn('blueprint_hooks', 'tags')) {
            Schema::table('blueprint_hooks', function (Blueprint $table) {
                $table->dropColumn('tags');
            });
        }
    }
};
