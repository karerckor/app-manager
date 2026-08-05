<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentry_dsns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('public_key', 64)->unique();
            $table->string('project_id', 20)->unique();
            $table->string('routing_method');
            $table->string('event_source')->nullable();
            $table->string('app_key', 120);
            $table->string('identifier_key', 120)->nullable();
            $table->longText('upstream_dsn')->nullable();
            $table->unsignedInteger('consecutive_forward_failures')->default(0);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_forwarded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'deleted_at']);
        });

        Schema::create('sentry_dsn_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sentry_dsn_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['sentry_dsn_id', 'mobile_app_id']);
        });

        Schema::create('sentry_envelopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sentry_dsn_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->nullOnDelete();
            $table->json('header')->nullable();
            $table->longText('body_base64');
            $table->string('status')->default('stored');
            $table->unsignedSmallInteger('forward_attempts')->default(0);
            $table->unsignedSmallInteger('upstream_response_status')->nullable();
            $table->text('forwarding_error')->nullable();
            $table->timestamp('next_forward_at')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();

            $table->index(['sentry_dsn_id', 'created_at']);
            $table->index(['status', 'next_forward_at']);
        });

        Schema::create('sentry_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sentry_dsn_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sentry_envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id', 64)->nullable();
            $table->string('item_type', 40);
            $table->string('level', 30)->nullable();
            $table->text('title')->nullable();
            $table->text('transaction')->nullable();
            $table->string('release')->nullable();
            $table->string('environment')->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['mobile_app_id', 'occurred_at']);
            $table->index(['team_id', 'item_type', 'occurred_at']);
            $table->index(['trace_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentry_events');
        Schema::dropIfExists('sentry_envelopes');
        Schema::dropIfExists('sentry_dsn_routes');
        Schema::dropIfExists('sentry_dsns');
    }
};
