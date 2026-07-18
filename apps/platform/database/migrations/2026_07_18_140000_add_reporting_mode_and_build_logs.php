<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->string('reporting_mode')->default('manual')->after('status');
            $table->longText('log_output')->nullable()->after('context_snapshot');
            $table->timestamp('logs_updated_at')->nullable()->after('log_output');
            $table->index(['reporting_mode', 'status']);
        });

        Schema::table('ci_cd_pipelines', function (Blueprint $table) {
            $table->string('reporting_mode')->default('automatic')->after('enabled');
            $table->string('build_id_input')->nullable()->after('reporting_mode');
        });

        Schema::table('ci_cd_runs', function (Blueprint $table) {
            $table->string('reporting_mode')->default('automatic')->after('dispatch_status');
        });

        DB::table('build_records')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('ci_cd_runs')
                ->whereColumn('ci_cd_runs.build_record_id', 'build_records.id'))
            ->update(['reporting_mode' => 'automatic']);

        DB::table('ci_cd_runs')
            ->whereNotNull('log_output')
            ->select(['build_record_id', 'log_output', 'logs_synced_at'])
            ->orderBy('build_record_id')
            ->each(fn ($run) => DB::table('build_records')
                ->where('id', $run->build_record_id)
                ->update([
                    'log_output' => $run->log_output,
                    'logs_updated_at' => $run->logs_synced_at,
                ]));
    }

    public function down(): void
    {
        Schema::table('ci_cd_runs', function (Blueprint $table) {
            $table->dropColumn('reporting_mode');
        });

        Schema::table('ci_cd_pipelines', function (Blueprint $table) {
            $table->dropColumn(['reporting_mode', 'build_id_input']);
        });

        Schema::table('build_records', function (Blueprint $table) {
            $table->dropIndex(['reporting_mode', 'status']);
            $table->dropColumn(['reporting_mode', 'log_output', 'logs_updated_at']);
        });
    }
};
