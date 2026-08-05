<?php

use App\Enums\TeamRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_user')
            ->where('role', 'member')
            ->update(['role' => TeamRole::Viewer->value]);

        Schema::table('team_user', function (Blueprint $table) {
            $table->string('role')->default(TeamRole::Viewer->value)->change();
        });
    }

    public function down(): void
    {
        DB::table('team_user')
            ->where('role', TeamRole::Viewer->value)
            ->update(['role' => 'member']);

        Schema::table('team_user', function (Blueprint $table) {
            $table->string('role')->default('member')->change();
        });
    }
};
