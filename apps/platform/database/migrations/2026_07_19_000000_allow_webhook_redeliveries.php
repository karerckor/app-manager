<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique(['webhook_event_id', 'webhook_endpoint_id']);
        });

        DB::statement('CREATE UNIQUE INDEX webhook_deliveries_original_unique ON webhook_deliveries (webhook_event_id, webhook_endpoint_id) WHERE redelivery_of_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX webhook_deliveries_original_unique');

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->unique(['webhook_event_id', 'webhook_endpoint_id']);
        });
    }
};
