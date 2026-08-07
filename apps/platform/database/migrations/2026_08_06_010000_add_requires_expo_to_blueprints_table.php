<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blueprints', function (Blueprint $table) {
            $table->boolean('requires_expo')->nullable()->after('platforms');
        });
    }

    public function down(): void
    {
        Schema::table('blueprints', function (Blueprint $table) {
            $table->dropColumn('requires_expo');
        });
    }
};
