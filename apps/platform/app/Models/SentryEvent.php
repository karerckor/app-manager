<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Integrations\Sentry\SentryIssueGrouper;

#[Fillable([
    'team_id',
    'sentry_dsn_id',
    'sentry_envelope_id',
    'sentry_issue_id',
    'mobile_app_id',
    'app_identifier_id',
    'event_id',
    'item_type',
    'level',
    'title',
    'transaction',
    'release',
    'environment',
    'platform',
    'trace_id',
    'occurred_at',
    'payload',
])]
class SentryEvent extends Model
{
    use HasUuids;

    public function sentryDsn(): BelongsTo
    {
        return $this->belongsTo(SentryDsn::class)->withTrashed();
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SentryEnvelope::class, 'sentry_envelope_id');
    }

    public function sentryIssue(): BelongsTo
    {
        return $this->belongsTo(SentryIssue::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (self $event) => app(SentryIssueGrouper::class)->assign($event));
    }
}
