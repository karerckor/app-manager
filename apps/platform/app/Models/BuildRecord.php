<?php

namespace App\Models;

use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'mobile_app_id',
    'app_identifier_id',
    'external_id',
    'profile_type',
    'status',
    'commit_sha',
    'ci_provider',
    'ci_run_id',
    'ci_run_url',
    'artifact_url',
    'context_schema_version',
    'context_snapshot',
    'started_at',
    'finished_at',
])]
class BuildRecord extends Model
{
    use HasFactory, HasUuids;

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

    public function logs(): HasMany
    {
        return $this->hasMany(BuildLog::class);
    }

    protected function casts(): array
    {
        return [
            'profile_type' => ProfileType::class,
            'status' => BuildStatus::class,
            'context_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
