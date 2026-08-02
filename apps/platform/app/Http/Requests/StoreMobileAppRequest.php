<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMobileApp;
use Illuminate\Foundation\Http\FormRequest;

class StoreMobileAppRequest extends FormRequest
{
    use ValidatesMobileApp;

    public function authorize(): bool
    {
        return $this->user()->teams()
            ->whereKey($this->route('team')->id)
            ->exists();
    }

    public function rules(): array
    {
        return $this->mobileAppRules(
            $this->route('team'),
        );
    }
}
