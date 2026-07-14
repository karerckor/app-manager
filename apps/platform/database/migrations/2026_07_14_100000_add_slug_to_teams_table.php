<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teams', 'slug')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
            });
        }

        $usedSlugs = DB::table('teams')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->pluck('slug')
            ->flip()
            ->all();

        DB::table('teams')
            ->whereNull('slug')
            ->orWhere('slug', '')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function (object $team) use (&$usedSlugs) {
                $baseSlug = Str::slug($team->name) ?: 'account';
                $slug = $baseSlug;
                $suffix = 2;

                while (isset($usedSlugs[$slug])) {
                    $slug = $baseSlug.'-'.$suffix++;
                }

                DB::table('teams')->where('id', $team->id)->update(['slug' => $slug]);
                $usedSlugs[$slug] = true;
            });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
