<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expo_projects', function (Blueprint $table) {
            $table->boolean('auto_sync_enabled')->default(false)->after('sync_metadata');
            $table->string('auto_sync_status')->default('idle')->after('auto_sync_enabled');
            $table->json('auto_sync_snapshot')->nullable()->after('auto_sync_status');
            $table->text('auto_sync_error')->nullable()->after('auto_sync_snapshot');
            $table->timestamp('auto_sync_pending_at')->nullable()->after('auto_sync_error');
            $table->timestamp('auto_sync_started_at')->nullable()->after('auto_sync_pending_at');
            $table->timestamp('last_pushed_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('expo_projects', function (Blueprint $table) {
            $table->dropColumn([
                'auto_sync_enabled',
                'auto_sync_status',
                'auto_sync_snapshot',
                'auto_sync_error',
                'auto_sync_pending_at',
                'auto_sync_started_at',
                'last_pushed_at',
            ]);
        });
    }
};
