<?php

namespace App\Http\Requests;

class StoreBlueprintHookRequest extends BlueprintHookRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }
}
