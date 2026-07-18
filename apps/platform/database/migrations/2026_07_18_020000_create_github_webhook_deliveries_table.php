<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->string('delivery_id', 100)->primary();
            $table->string('event', 100);
            $table->string('installation_id')->nullable();
            $table->string('payload_hash', 64);
            $table->longText('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
