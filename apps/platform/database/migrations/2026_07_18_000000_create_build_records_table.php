<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_uuid');
            $table->string('profile_type');
            $table->string('status')->default('pending');
            $table->json('context_snapshot');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'request_uuid']);
            $table->index(['team_id', 'status']);
            $table->index(['mobile_app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_records');
    }
};
