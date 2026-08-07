<?php

namespace App\Models;

use App\Enums\BlueprintHookCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['blueprint_id', 'name', 'condition', 'condition_config', 'actions', 'enabled'])]
class BlueprintHook extends Model
{
    use HasUuids, SoftDeletes;

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    protected function casts(): array
    {
        return [
            'condition' => BlueprintHookCondition::class,
            'condition_config' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
