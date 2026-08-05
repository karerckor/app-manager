<?php

namespace App\Models;

use App\Enums\SentryEnvelopeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'sentry_dsn_id',
    'mobile_app_id',
    'app_identifier_id',
    'header',
    'body_base64',
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
        return base64_decode($this->body_base64, true) ?: '';
    }

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'status' => SentryEnvelopeStatus::class,
            'next_forward_at' => 'datetime',
            'forwarded_at' => 'datetime',
        ];
    }
}
