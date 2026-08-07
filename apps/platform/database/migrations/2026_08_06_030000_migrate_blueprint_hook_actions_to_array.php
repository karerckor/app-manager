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

        if (! Schema::hasColumn('blueprint_hooks', 'actions')) {
            Schema::table('blueprint_hooks', function (Blueprint $table) {
                $table->json('actions')->nullable()->after('condition_config');
            });
        }

        if (Schema::hasColumn('blueprint_hooks', 'action')
            && Schema::hasColumn('blueprint_hooks', 'tags')) {
            DB::table('blueprint_hooks')
                ->whereNull('actions')
                ->orderBy('id')
                ->get(['id', 'action', 'tags'])
                ->each(function (object $hook): void {
                    $tags = is_string($hook->tags)
                        ? json_decode($hook->tags, true, flags: JSON_THROW_ON_ERROR)
                        : $hook->tags;

                    DB::table('blueprint_hooks')->where('id', $hook->id)->update([
                        'actions' => json_encode([[
                            'type' => $hook->action,
                            'tags' => is_array($tags) ? $tags : [],
                        ]], JSON_THROW_ON_ERROR),
                    ]);
                });
        }

        $legacyColumns = collect(['action', 'tags'])
            ->filter(fn (string $column) => Schema::hasColumn('blueprint_hooks', $column))
            ->values()
            ->all();

        if ($legacyColumns !== []) {
            Schema::table('blueprint_hooks', function (Blueprint $table) use ($legacyColumns) {
                $table->dropColumn($legacyColumns);
            });
        }
    }

    public function down(): void
    {
        // `actions` can contain several ordered operations and cannot be
        // represented losslessly by the former scalar columns.
    }
};
