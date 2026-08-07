<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->json('tags')->default('[]')->after('title');
            $table->text('status_comment')->nullable()->after('resolved_by_api_key_id');
            $table->timestamp('status_changed_at')->nullable()->after('status_comment');
            $table->foreignUuid('status_changed_by_user_id')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('status_changed_by_api_key_id')->nullable()->after('status_changed_by_user_id');
            $table->foreign('status_changed_by_api_key_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });

        DB::table('sentry_issues')->where('status', 'resolved')->update([
            'status_changed_at' => DB::raw('resolved_at'),
            'status_changed_by_user_id' => DB::raw('resolved_by_user_id'),
            'status_changed_by_api_key_id' => DB::raw('resolved_by_api_key_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->dropForeign(['status_changed_by_api_key_id']);
            $table->dropConstrainedForeignId('status_changed_by_user_id');
            $table->dropColumn(['status_changed_by_api_key_id', 'status_changed_at', 'status_comment', 'tags']);
        });
    }
};
