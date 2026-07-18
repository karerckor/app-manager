<?php

namespace App\Models;

use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'team_id',
    'mobile_app_id',
    'app_identifier_id',
    'requested_by_user_id',
    'request_uuid',
    'profile_type',
    'status',
    'context_snapshot',
    'started_at',
    'cancel_requested_at',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function ciCdRun(): HasOne
    {
        return $this->hasOne(CiCdRun::class);
    }

    protected function casts(): array
    {
        return [
            'profile_type' => ProfileType::class,
            'status' => BuildStatus::class,
            'context_snapshot' => 'array',
            'started_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
