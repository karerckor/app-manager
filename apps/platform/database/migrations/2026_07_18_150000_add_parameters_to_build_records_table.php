<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->longText('parameters')->nullable()->after('context_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
