<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('team_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->orderBy('id')
            ->chunkById(100, function ($tokens): void {
                $teamIds = DB::table('users')
                    ->whereIn('id', $tokens->pluck('tokenable_id')->unique())
                    ->pluck('current_team_id', 'id');

                foreach ($tokens as $token) {
                    $teamId = $teamIds[$token->tokenable_id] ?? null;

                    if ($teamId !== null) {
                        DB::table('personal_access_tokens')
                            ->where('id', $token->id)
                            ->update(['team_id' => $teamId]);
                    }
                }
            });

        // Tokens that cannot be assigned safely to a team must not remain valid.
        DB::table('personal_access_tokens')->whereNull('team_id')->delete();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('team_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
