<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encryption_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->text('key_material');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};
