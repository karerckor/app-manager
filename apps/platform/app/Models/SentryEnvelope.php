<?php

namespace App\Models;

use App\Enums\SentryEnvelopeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'team_id',
    'sentry_dsn_id',
    'mobile_app_id',
    'app_identifier_id',
    'header',
    'body_base64',
    'archive_disk',
    'archive_path',
    'archive_size',
    'archive_checksum',
    'archived_at',
    'status',
    'forward_attempts',
    'upstream_response_status',
    'forwarding_error',
    'next_forward_at',
    'forwarded_at',
])]
class SentryEnvelope extends Model
{
    use HasUuids;

    public function sentryDsn(): BelongsTo
    {
        return $this->belongsTo(SentryDsn::class)->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SentryEvent::class);
    }

    public function body(): string
    {
        return is_string($this->body_base64)
            ? (base64_decode($this->body_base64, true) ?: '')
            : '';
    }

    protected static function booted(): void
    {
        static::deleting(function (SentryEnvelope $envelope): void {
            if ($envelope->archive_disk && $envelope->archive_path) {
                Storage::disk($envelope->archive_disk)->delete($envelope->archive_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'status' => SentryEnvelopeStatus::class,
            'archive_size' => 'integer',
            'archived_at' => 'datetime',
            'next_forward_at' => 'datetime',
            'forwarded_at' => 'datetime',
        ];
    }
}
