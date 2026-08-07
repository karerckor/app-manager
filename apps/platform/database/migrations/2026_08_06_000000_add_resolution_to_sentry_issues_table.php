<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('last_seen_at');
            $table->foreignUuid('resolved_by_user_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('resolved_by_api_key_id')->nullable()->after('resolved_by_user_id');
            $table->foreign('resolved_by_api_key_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->dropForeign(['resolved_by_api_key_id']);
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['resolved_by_api_key_id', 'resolved_at']);
        });
    }
};
