<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expo_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('account_id');
            $table->string('account_name');
            $table->string('slug');
            $table->string('name');
            $table->string('full_name');
            $table->text('description')->nullable();
            $table->text('icon_url')->nullable();
            $table->boolean('sync_metadata')->default(true);
            $table->boolean('exists_on_expo')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_id']);
            $table->index(['team_id', 'exists_on_expo']);
        });

        Schema::create('expo_credential_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expo_project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('credential_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->constrained()->cascadeOnDelete();
            $table->string('remote_resource_type');
            $table->string('remote_resource_id');
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['expo_project_id', 'credential_id', 'app_identifier_id'],
                'expo_credential_project_credential_identifier_unique',
            );
            $table->index(['integration_id', 'remote_resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expo_credential_links');
        Schema::dropIfExists('expo_projects');
    }
};
