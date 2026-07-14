<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_identifier_id')->constrained()->cascadeOnDelete();
            $table->string('type');           // ProfileType enum
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['app_identifier_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
