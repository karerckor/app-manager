<?php

use App\Integrations\Sentry\SentryIssueGrouper;
use App\Models\SentryEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentry_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('app_identifier_id')->nullable()->constrained()->nullOnDelete();
            // Identifier-specific and application-wide routes need distinct groups.
            $table->string('scope_key', 64);
            $table->string('fingerprint', 64);
            $table->string('grouping_version', 20)->default('v1');
            $table->string('status', 30)->default('unresolved');
            $table->string('level', 30)->nullable();
            $table->text('title');
            $table->unsignedBigInteger('events_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['mobile_app_id', 'scope_key', 'fingerprint'], 'sentry_issues_scope_fingerprint_unique');
            $table->index(['mobile_app_id', 'status', 'last_seen_at']);
        });

        Schema::table('sentry_events', function (Blueprint $table) {
            $table->foreignUuid('sentry_issue_id')->nullable()->after('sentry_envelope_id')
                ->constrained()->nullOnDelete();
            $table->index(['sentry_issue_id', 'occurred_at']);
        });

        // The relay can already have raw events when this schema reaches an
        // existing workspace. Preserve them and make the new Issue inbox useful
        // immediately instead of grouping only future deliveries.
        SentryEvent::query()
            ->where('item_type', 'event')
            ->whereNull('sentry_issue_id')
            ->orderBy('id')
            ->eachById(fn (SentryEvent $event) => app(SentryIssueGrouper::class)->assign($event));
    }

    public function down(): void
    {
        Schema::table('sentry_events', function (Blueprint $table) {
            $table->dropIndex(['sentry_issue_id', 'occurred_at']);
            $table->dropConstrainedForeignId('sentry_issue_id');
        });

        Schema::dropIfExists('sentry_issues');
    }
};
