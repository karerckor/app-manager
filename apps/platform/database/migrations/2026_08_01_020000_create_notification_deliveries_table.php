<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->timestamp('notifications_published_at')->nullable()->after('published_at');
            $table->index(['notifications_published_at', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('webhook_event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('notification_destination_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('rendered_message');
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_event_id', 'notification_destination_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['notifications_published_at', 'created_at']);
            $table->dropColumn('notifications_published_at');
        });
    }
};
