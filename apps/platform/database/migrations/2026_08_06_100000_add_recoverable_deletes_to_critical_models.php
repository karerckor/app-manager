<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'users',
        'teams',
        'mobile_apps',
        'app_identifiers',
        'profiles',
        'credentials',
        'build_records',
        'artifacts',
        'blueprints',
        'blueprint_hooks',
        'sentry_issues',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
