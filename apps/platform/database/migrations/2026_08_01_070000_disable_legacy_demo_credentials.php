<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment(['local', 'development', 'testing'])) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('token', hash('sha256', 'demo-api-key'))
            ->delete();

        DB::table('users')
            ->whereIn('email', ['demo@example.com', 'test@example.com'])
            ->update([
                'password' => Hash::make(Str::random(64)),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Known demo credentials must never be restored outside development.
    }
};
