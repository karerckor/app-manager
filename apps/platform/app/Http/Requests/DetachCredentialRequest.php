<?php

namespace App\Http\Requests;

use App\Enums\ProfileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DetachCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_type' => ['nullable', Rule::enum(ProfileType::class)],
        ];
    }
}
