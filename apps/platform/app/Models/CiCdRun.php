<?php

namespace App\Models;

use App\Enums\CiCdDispatchStatus;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id',
    'build_record_id',
    'pipeline_id',
    'integration_id',
    'requested_by_user_id',
    'dispatch_status',
    'external_run_id',
    'external_run_url',
    'provider_status',
    'provider_conclusion',
    'request_snapshot',
    'dispatched_at',
    'last_synced_at',
])]
class CiCdRun extends Model
{
    use HasFactory, HasUuids;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function buildRecord(): BelongsTo
    {
        return $this->belongsTo(BuildRecord::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CiCdPipeline::class, 'pipeline_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'dispatch_status' => CiCdDispatchStatus::class,
            'request_snapshot' => 'encrypted:array',
            'dispatched_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CiCdRun $run) {
            $buildMatches = BuildRecord::query()
                ->whereKey($run->build_record_id)
                ->where('team_id', $run->team_id)
                ->exists();
            $pipelineMatches = CiCdPipeline::query()
                ->whereKey($run->pipeline_id)
                ->where('team_id', $run->team_id)
                ->where('integration_id', $run->integration_id)
                ->exists();

            if (! $buildMatches || ! $pipelineMatches) {
                throw new DomainException('CI/CD run resources must belong to the same team and integration.');
            }
        });
    }
}
