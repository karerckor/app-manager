<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_cd_runs', function (Blueprint $table) {
            $table->longText('log_output')->nullable()->after('provider_conclusion');
            $table->timestamp('logs_synced_at')->nullable()->after('log_output');
        });
    }

    public function down(): void
    {
        Schema::table('ci_cd_runs', function (Blueprint $table) {
            $table->dropColumn(['log_output', 'logs_synced_at']);
        });
    }
};
