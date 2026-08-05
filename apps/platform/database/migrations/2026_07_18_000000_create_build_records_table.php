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
            $table->foreignUuid('mobile_app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_type');
            $table->string('status');
            $table->string('commit_sha', 64)->nullable();
            $table->string('ci_provider')->nullable();
            $table->string('ci_run_id')->nullable();
            $table->text('ci_run_url')->nullable();
            $table->text('artifact_url')->nullable();
            $table->unsignedInteger('context_schema_version');
            $table->json('context_snapshot');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'ci_provider', 'ci_run_id']);
            $table->index(['team_id', 'status', 'created_at']);
            $table->index(['mobile_app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_records');
    }
};
