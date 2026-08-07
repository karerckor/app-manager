<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_dsns', function (Blueprint $table) {
            $table->string('target_mode')->default('selected')->after('identifier_key');
        });

        Schema::create('blueprint_sentry_dsn', function (Blueprint $table) {
            $table->foreignUuid('sentry_dsn_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('blueprint_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['sentry_dsn_id', 'blueprint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_sentry_dsn');
        Schema::dropIfExists('sentry_dsn_blueprint');

        Schema::table('sentry_dsns', function (Blueprint $table) {
            $table->dropColumn('target_mode');
        });
    }
};
