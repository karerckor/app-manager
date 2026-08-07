<?php

namespace App\Http\Requests;

class UpdateBlueprintHookRequest extends BlueprintHookRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
