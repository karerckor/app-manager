<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('destination');
            $table->json('subscribed_events');
            $table->json('templates');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_destinations');
    }
};
