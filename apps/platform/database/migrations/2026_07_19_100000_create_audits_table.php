<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('user', 'audits_user_index');
            $table->string('event');
            $table->string('auditable_type');
            $table->string('auditable_id');
            $table->index(['auditable_type', 'auditable_id'], 'audits_auditable_index');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();

            // Custom columns populated by App\Support\Audit\Resolvers\*
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            // personal_access_tokens.id is a bigint auto-increment, NOT a UUID — match it.
            $table->unsignedBigInteger('api_key_id')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
