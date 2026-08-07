<?php

namespace App\Http\Requests;

class StoreBlueprintRequest extends BlueprintRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }
}
