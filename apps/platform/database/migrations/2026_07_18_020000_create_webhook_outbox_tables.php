<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->longText('secret');
            $table->string('secret_hint', 8);
            $table->json('subscribed_events');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'deleted_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('aggregate_type');
            $table->uuid('aggregate_id')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'event_type', 'occurred_at']);
            $table->index(['published_at', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('webhook_event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('redelivery_of_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('payload_snapshot');
            $table->string('signature_version')->default('v1');
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_event_id', 'webhook_endpoint_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreign('redelivery_of_id')
                ->references('id')
                ->on('webhook_deliveries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_endpoints');
    }
};
