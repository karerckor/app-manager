<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('destination');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('templates');
            $table->timestamp('last_delivered_at')->nullable()->after('templates');
        });
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'consecutive_failures', 'last_delivered_at']);
        });
    }
};
