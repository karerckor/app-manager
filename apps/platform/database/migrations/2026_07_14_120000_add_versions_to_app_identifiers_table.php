<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_identifiers', function (Blueprint $table) {
            $table->string('version')->nullable();
            $table->unsignedBigInteger('version_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_identifiers', function (Blueprint $table) {
            $table->dropColumn(['version', 'version_code']);
        });
    }
};
