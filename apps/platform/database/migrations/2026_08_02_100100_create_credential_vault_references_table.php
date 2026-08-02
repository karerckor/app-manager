<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_vault_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                 // VaultProvider enum
            $table->text('resource');                   // ARN / path / URI, deliberately not encrypted
            $table->jsonb('metadata')->nullable();      // storage metadata only
            $table->unsignedInteger('metadata_version')->default(1);
            $table->string('metadata_source')->default('user');
            $table->string('verification_status')->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_error')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'provider', 'resource'], 'vault_references_uniqueness_lookup');
            $table->index(['team_id', 'verification_status'], 'vault_references_status_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_vault_references');
    }
};
