<?php

namespace App\Http\Requests;

class UpdateBlueprintRequest extends BlueprintRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
