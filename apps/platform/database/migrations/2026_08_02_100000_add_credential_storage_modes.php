<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->string('storage_mode')->default('local');
            $table->index(['team_id', 'storage_mode'], 'credentials_storage_mode_lookup');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->jsonb('allowed_credential_storage_modes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('credentials_storage_mode_lookup');
            $table->dropColumn('storage_mode');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('allowed_credential_storage_modes');
        });
    }
};
