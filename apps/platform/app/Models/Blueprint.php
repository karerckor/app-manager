<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable(['team_id', 'name', 'description', 'json_schema', 'required_files', 'platforms', 'requires_expo'])]
class Blueprint extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (Blueprint $blueprint): void {
            if (! $blueprint->isForceDeleting()) {
                return;
            }

            $blueprint->hooks()->withTrashed()->cursor()
                ->each(fn (BlueprintHook $hook) => $hook->forceDelete());
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function mobileApps(): HasMany
    {
        return $this->hasMany(MobileApp::class);
    }

    public function hooks(): HasMany
    {
        return $this->hasMany(BlueprintHook::class);
    }

    protected function casts(): array
    {
        return [
            'json_schema' => 'array',
            'required_files' => 'array',
            'platforms' => 'array',
            'requires_expo' => 'boolean',
        ];
    }
}
