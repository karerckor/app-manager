<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->json('filters')->nullable()->after('subscribed_events');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->json('filter_context')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('filter_context');
        });

        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }
};
