<?php

namespace App\Models;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationProvider;
use App\Enums\ProfileType;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'team_id',
    'integration_id',
    'mobile_app_id',
    'app_identifier_id',
    'name',
    'profile_type',
    'enabled',
    'reporting_mode',
    'build_id_input',
    'repository_id',
    'repository_full_name',
    'workflow_id',
    'workflow_name',
    'workflow_path',
    'default_ref',
    'input_schema',
    'input_defaults',
    'created_by_user_id',
    'updated_by_user_id',
])]
class CiCdPipeline extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'reporting_mode' => 'automatic',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CiCdRun::class, 'pipeline_id');
    }

    protected function casts(): array
    {
        return [
            'profile_type' => ProfileType::class,
            'enabled' => 'boolean',
            'reporting_mode' => BuildReportingMode::class,
            'input_schema' => 'encrypted:array',
            'input_defaults' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CiCdPipeline $pipeline) {
            $integration = Integration::query()
                ->whereKey($pipeline->integration_id)
                ->where('team_id', $pipeline->team_id)
                ->first();
            $integrationMatches = $integration?->supportsCiCd() ?? false;
            $appMatches = MobileApp::query()
                ->whereKey($pipeline->mobile_app_id)
                ->where('team_id', $pipeline->team_id)
                ->exists();
            $identifierMatches = $pipeline->app_identifier_id === null
                || AppIdentifier::query()
                    ->whereKey($pipeline->app_identifier_id)
                    ->where('mobile_app_id', $pipeline->mobile_app_id)
                    ->exists();

            $expoProjectMatches = $integration?->provider !== IntegrationProvider::Expo
                || ExpoProject::query()
                    ->where('integration_id', $integration->id)
                    ->where('mobile_app_id', $pipeline->mobile_app_id)
                    ->where('external_id', $pipeline->repository_id)
                    ->where('exists_on_expo', true)
                    ->exists();

            if (! $integrationMatches || ! $appMatches || ! $identifierMatches || ! $expoProjectMatches) {
                throw new DomainException('Pipeline resources must belong to the same team and application.');
            }
        });
    }
}
