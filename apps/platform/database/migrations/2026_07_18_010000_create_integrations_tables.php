<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('provider');
            $table->string('name');
            $table->string('status')->default('pending');
            $table->string('external_id')->nullable();
            $table->string('external_account_id')->nullable();
            $table->string('external_account_name')->nullable();
            $table->json('settings')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'provider', 'external_id']);
            $table->index(['team_id', 'kind', 'status']);
        });

        Schema::create('ci_cd_pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('profile_type');
            $table->boolean('enabled')->default(true);
            $table->string('repository_id');
            $table->string('repository_full_name');
            $table->string('workflow_id');
            $table->string('workflow_name');
            $table->string('workflow_path');
            $table->string('default_ref');
            $table->longText('input_schema')->nullable();
            $table->longText('input_defaults')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'enabled']);
            $table->index(['mobile_app_id', 'app_identifier_id', 'enabled']);
            $table->unique(['integration_id', 'repository_id', 'workflow_id', 'mobile_app_id', 'app_identifier_id'], 'ci_cd_pipeline_binding_unique');
        });

        Schema::create('ci_cd_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('build_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('pipeline_id')->constrained('ci_cd_pipelines')->cascadeOnDelete();
            $table->foreignUuid('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispatch_status')->default('created');
            $table->string('external_run_id')->nullable();
            $table->text('external_run_url')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('provider_conclusion')->nullable();
            $table->longText('request_snapshot');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_run_id']);
            $table->index(['dispatch_status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_cd_runs');
        Schema::dropIfExists('ci_cd_pipelines');
        Schema::dropIfExists('integrations');
    }
};
