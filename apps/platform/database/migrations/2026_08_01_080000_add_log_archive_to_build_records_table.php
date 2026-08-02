<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->string('log_archive_disk')->nullable()->after('logs_updated_at');
            $table->string('log_archive_path')->nullable()->after('log_archive_disk');
            $table->unsignedBigInteger('log_archive_size')->nullable()->after('log_archive_path');
            $table->timestamp('logs_archived_at')->nullable()->after('log_archive_size');
            $table->index('logs_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->dropIndex(['logs_updated_at']);
            $table->dropColumn([
                'log_archive_disk',
                'log_archive_path',
                'log_archive_size',
                'logs_archived_at',
            ]);
        });
    }
};
