<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_apps');
    }
};
