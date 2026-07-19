<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('build_record_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['build_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
