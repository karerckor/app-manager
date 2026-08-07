<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blueprint_hooks')
            || ! Schema::hasColumn('blueprint_hooks', 'tags')) {
            return;
        }

        $legacyColumns = collect(['tag', 'tag_color', 'tag_animation'])
            ->filter(fn (string $column) => Schema::hasColumn('blueprint_hooks', $column))
            ->values()
            ->all();

        if ($legacyColumns === []) {
            return;
        }

        Schema::table('blueprint_hooks', function (Blueprint $table) use ($legacyColumns) {
            $table->dropColumn($legacyColumns);
        });
    }

    public function down(): void
    {
        // The scalar columns are obsolete and cannot faithfully represent an
        // array of tag tuples. `tags` remains the lossless rollback source.
    }
};
