<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->string('platform');       // Platform enum
            $table->string('identifier');     // bundle id / package name
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['mobile_app_id', 'platform', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_identifiers');
    }
};
