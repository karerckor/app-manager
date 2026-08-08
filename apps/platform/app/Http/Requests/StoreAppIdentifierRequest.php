<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Http\Requests\Concerns\ValidatesAppIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $createMobilePair = $this->boolean('create_for_both_platforms');

        return [
            ...$this->identifierRules(
                $this->route('mobileApp'),
                createMobilePair: $createMobilePair,
            ),
            'create_for_both_platforms' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn () => $createMobilePair
                    && $this->input('platform') === Platform::Web->value),
            ],
        ];
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
