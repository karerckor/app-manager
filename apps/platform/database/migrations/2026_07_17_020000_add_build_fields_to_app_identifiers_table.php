<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_INDEX = 'app_identifiers_default_platform_unique';

    public function up(): void
    {
        Schema::table('app_identifiers', function (Blueprint $table) {
            $table->string('build_number')->nullable();
            $table->boolean('is_default')->default(false);
        });

        $condition = DB::getDriverName() === 'pgsql'
            ? 'is_default = true'
            : 'is_default = 1';

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON app_identifiers (mobile_app_id, platform) WHERE %s',
            self::DEFAULT_INDEX,
            $condition,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::DEFAULT_INDEX);

        Schema::table('app_identifiers', function (Blueprint $table) {
            $table->dropColumn(['build_number', 'is_default']);
        });
    }
};
