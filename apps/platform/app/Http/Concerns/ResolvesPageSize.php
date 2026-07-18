<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait ResolvesPageSize
{
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $value = $request->integer('per_page', $default);

        if ($value < 1) {
            return $default;
        }

        return min($value, $max);
    }
}
