<?php

namespace App\Http\Requests;

use App\Rules\ValidCredentialFile;
use Illuminate\Foundation\Http\FormRequest;

class UploadCredentialFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480',
                new ValidCredentialFile($this->route('credential')->type, $this->route('credential')->payload ?? []),
            ],
        ];
    }
}
