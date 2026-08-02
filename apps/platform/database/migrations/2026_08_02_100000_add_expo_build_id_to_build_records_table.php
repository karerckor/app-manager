<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->string('expo_build_id')->nullable()->after('reporting_mode');
            $table->index(['expo_build_id']);
        });
    }

    public function down(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->dropIndex(['expo_build_id']);
            $table->dropColumn('expo_build_id');
        });
    }
};
