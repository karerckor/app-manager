<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->longText('settings');
            $table->foreignUuid('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
