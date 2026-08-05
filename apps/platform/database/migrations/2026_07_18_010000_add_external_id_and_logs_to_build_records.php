<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_records', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('app_identifier_id');
            $table->timestamp('started_at')->nullable()->change();
            $table->unique(['team_id', 'external_id']);
        });

        Schema::create('build_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('build_record_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('stream', 16)->default('stdout');
            $table->string('level', 16)->default('info');
            $table->text('message');
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            $table->unique(['build_record_id', 'sequence']);
            $table->index(['build_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_logs');

        DB::table('build_records')
            ->whereNull('started_at')
            ->update(['started_at' => DB::raw('created_at')]);

        Schema::table('build_records', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'external_id']);
            $table->dropColumn('external_id');
            $table->timestamp('started_at')->nullable(false)->change();
        });
    }
};
