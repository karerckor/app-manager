<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_dsn_routes', function (Blueprint $table) {
            $table->string('public_key', 64)->nullable()->unique()->after('id');
        });

        DB::table('sentry_dsn_routes')->orderBy('id')->eachById(function ($route) {
            DB::table('sentry_dsn_routes')->where('id', $route->id)->update([
                'public_key' => bin2hex(random_bytes(16)),
            ]);
        }, column: 'id');
    }

    public function down(): void
    {
        Schema::table('sentry_dsn_routes', function (Blueprint $table) {
            $table->dropUnique(['public_key']);
            $table->dropColumn('public_key');
        });
    }
};
