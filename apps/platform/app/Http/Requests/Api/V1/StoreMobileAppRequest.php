<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesMobileApp;
use Illuminate\Foundation\Http\FormRequest;

class StoreMobileAppRequest extends FormRequest
{
    use ValidatesMobileApp;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->mobileAppRules(
            $this->route('team'),
        );
    }
}
