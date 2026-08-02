<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_invitations')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Plaintext invitation tokens cannot be recovered safely.
    }
};
