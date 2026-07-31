<?php

namespace App\Models;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'team_id',
    'kind',
    'provider',
    'name',
    'status',
    'external_id',
    'external_account_id',
    'external_account_name',
    'settings',
    'created_by_user_id',
    'updated_by_user_id',
    'last_synced_at',
    'disabled_at',
    'disconnected_at',
])]
class Integration extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    public function scopeConnectedExpo(Builder $query): Builder
    {
        return $query
            ->where('provider', IntegrationProvider::Expo->value)
            ->whereIn('status', [
                IntegrationStatus::Active->value,
                IntegrationStatus::Degraded->value,
            ])
            ->whereHas('oauthCredential');
    }

    public function scopeActiveExpo(Builder $query): Builder
    {
        return $query
            ->where('provider', IntegrationProvider::Expo->value)
            ->where('status', IntegrationStatus::Active->value)
            ->whereHas('oauthCredential');
    }

    public function supportsCiCd(): bool
    {
        return $this->kind === IntegrationKind::CiCd
            || ($this->kind === IntegrationKind::AppService
                && $this->provider === IntegrationProvider::Expo);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(CiCdPipeline::class);
    }

    public function ciCdRuns(): HasMany
    {
        return $this->hasMany(CiCdRun::class);
    }

    public function oauthCredential(): HasOne
    {
        return $this->hasOne(IntegrationOAuthCredential::class);
    }

    public function webhookEndpoint(): HasOne
    {
        return $this->hasOne(WebhookEndpoint::class);
    }

    public function expoProjects(): HasMany
    {
        return $this->hasMany(ExpoProject::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => IntegrationKind::class,
            'provider' => IntegrationProvider::class,
            'status' => IntegrationStatus::class,
            // NOTE: `settings` is NOT encrypted and is intentionally auditable (non-secret
            // provider config only). This model is Auditable, so any non-excluded attribute
            // is copied verbatim into the `audits` table — never store secrets/tokens here.
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'disabled_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }
}
