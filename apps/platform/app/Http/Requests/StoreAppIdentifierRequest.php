<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAppIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppIdentifierRequest extends FormRequest
{
    use ValidatesAppIdentifier;

    public function authorize(): bool
    {
        return $this->user()->teams()
            ->whereKey($this->route('team')->id)
            ->exists();
    }

    public function rules(): array
    {
        return $this->identifierRules(
            $this->route('mobileApp'),
        );
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeWebIdentifier();
    }

    private function normalizeWebIdentifier(): void
    {
        if ($this->input('platform') === 'web' && is_string($this->input('identifier'))) {
            $this->merge(['identifier' => strtolower(trim($this->input('identifier')))]);
        }
    }
}
