<?php

namespace App\Casts;

use App\Support\Tags\TagRules;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<list<array{0: string, 1?: string|null, 2?: string|null}>, array<int, mixed>|null>
 */
class Tags implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return TagRules::normalize(is_array($value) ? $value : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $tags = TagRules::normalize(is_array($value) ? $value : null);

        return json_encode($tags, JSON_THROW_ON_ERROR);
    }
}
