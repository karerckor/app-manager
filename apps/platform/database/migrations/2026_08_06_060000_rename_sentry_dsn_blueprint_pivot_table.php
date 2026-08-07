<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sentry_dsn_blueprint')
            && ! Schema::hasTable('blueprint_sentry_dsn')) {
            Schema::rename('sentry_dsn_blueprint', 'blueprint_sentry_dsn');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('blueprint_sentry_dsn')
            && ! Schema::hasTable('sentry_dsn_blueprint')) {
            Schema::rename('blueprint_sentry_dsn', 'sentry_dsn_blueprint');
        }
    }
};
