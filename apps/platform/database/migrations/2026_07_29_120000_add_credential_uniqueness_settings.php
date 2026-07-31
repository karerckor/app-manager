<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('check_credential_uniqueness_by_default')->default(true);
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable();
            $table->index(
                ['team_id', 'type', 'fingerprint'],
                'credentials_uniqueness_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('credentials_uniqueness_lookup');
            $table->dropColumn('fingerprint');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('check_credential_uniqueness_by_default');
        });
    }
};
