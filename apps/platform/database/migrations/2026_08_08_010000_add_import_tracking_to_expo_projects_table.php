<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expo_projects', function (Blueprint $table) {
            $table->string('import_status')->default('idle')->after('sync_metadata');
            $table->text('import_error')->nullable()->after('import_status');
            $table->timestamp('import_started_at')->nullable()->after('import_error');
            $table->timestamp('import_finished_at')->nullable()->after('import_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('expo_projects', function (Blueprint $table) {
            $table->dropColumn([
                'import_status',
                'import_error',
                'import_started_at',
                'import_finished_at',
            ]);
        });
    }
};
