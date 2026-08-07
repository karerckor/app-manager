<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
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
    'mobile_app_id',
    'app_identifier_id',
    'scope_key',
    'fingerprint',
    'grouping_version',
    'status',
    'level',
    'title',
    'tags',
    'events_count',
    'first_seen_at',
    'last_seen_at',
    'resolved_at',
    'resolved_by_user_id',
    'resolved_by_api_key_id',
    'status_comment',
    'status_changed_at',
    'status_changed_by_user_id',
    'status_changed_by_api_key_id',
])]
class SentryIssue extends Model implements Auditable
{
    use AuditableTrait {
        readyForAuditing as private traitReadyForAuditing;
    }
    use HasTags, HasUuids, SoftDeletes;

    protected array $auditEvents = ['updated'];

    protected array $auditInclude = [
        'status', 'tags', 'resolved_at', 'resolved_by_user_id', 'resolved_by_api_key_id',
        'status_comment', 'status_changed_at', 'status_changed_by_user_id', 'status_changed_by_api_key_id',
    ];

    public function readyForAuditing(): bool
    {
        if (! $this->traitReadyForAuditing() || $this->getAuditEvent() !== 'updated') {
            return false;
        }

        return array_intersect(array_keys($this->getChanges()), $this->auditInclude) !== [];
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
        return $this->belongsTo(AppIdentifier::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SentryEvent::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function resolvedByApiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'resolved_by_api_key_id');
    }

    public function statusChangedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function statusChangedByApiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'status_changed_by_api_key_id');
    }

    protected function casts(): array
    {
        return [
            'events_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }
}
