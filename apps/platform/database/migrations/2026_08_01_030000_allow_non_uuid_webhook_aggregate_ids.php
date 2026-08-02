<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE webhook_events
                ALTER COLUMN aggregate_id TYPE VARCHAR(255)
                USING aggregate_id::text
                SQL);

            return;
        }

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('aggregate_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE webhook_events
                ALTER COLUMN aggregate_id TYPE UUID
                USING aggregate_id::uuid
                SQL);

            return;
        }

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->uuid('aggregate_id')->nullable()->change();
        });
    }
};
