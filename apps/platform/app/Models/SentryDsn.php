<?php

namespace App\Models;

use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'team_id',
    'integration_id',
    'public_key',
    'project_id',
    'routing_method',
    'event_source',
    'app_key',
    'identifier_key',
    'upstream_dsn',
    'consecutive_forward_failures',
    'last_event_at',
    'last_forwarded_at',
])]
class SentryDsn extends Model implements Auditable
{
    use AuditableTrait, HasUuids, SoftDeletes;

    protected array $auditExclude = ['upstream_dsn'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(SentryDsnRoute::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(SentryEnvelope::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SentryEvent::class);
    }

    public function dsn(?string $publicKey = null): string
    {
        $parts = parse_url(config('app.url'));
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $host = (string) ($parts['host'] ?? 'localhost');
        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return "{$scheme}://".($publicKey ?? $this->public_key)."@{$host}{$port}{$path}/{$this->project_id}";
    }

    protected function casts(): array
    {
        return [
            'routing_method' => SentryRoutingMethod::class,
            'event_source' => SentryEventSource::class,
            'upstream_dsn' => 'encrypted',
            'last_event_at' => 'datetime',
            'last_forwarded_at' => 'datetime',
        ];
    }
}
