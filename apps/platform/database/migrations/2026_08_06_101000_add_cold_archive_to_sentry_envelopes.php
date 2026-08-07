<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_envelopes', function (Blueprint $table): void {
            $table->longText('body_base64')->nullable()->change();
            $table->string('archive_disk')->nullable()->after('body_base64');
            $table->string('archive_path')->nullable()->after('archive_disk');
            $table->unsignedBigInteger('archive_size')->nullable()->after('archive_path');
            $table->string('archive_checksum', 64)->nullable()->after('archive_size');
            $table->timestamp('archived_at')->nullable()->after('archive_checksum');
            $table->index(['archived_at', 'created_at']);
        });

        Schema::table('sentry_events', function (Blueprint $table): void {
            $table->json('payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sentry_events', function (Blueprint $table): void {
            $table->json('payload')->nullable(false)->change();
        });

        Schema::table('sentry_envelopes', function (Blueprint $table): void {
            $table->dropIndex(['archived_at', 'created_at']);
            $table->dropColumn([
                'archive_disk',
                'archive_path',
                'archive_size',
                'archive_checksum',
                'archived_at',
            ]);
            $table->longText('body_base64')->nullable(false)->change();
        });
    }
};
