<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Http\Requests\Concerns\ValidatesAppIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppIdentifierRequest extends FormRequest
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
            $this->route('appIdentifier'),
            update: true,
        );
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeWebIdentifier();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $appIdentifier = $this->route('appIdentifier');
            $platform = $this->input('platform', $appIdentifier->platform->value);

            if ($platform === Platform::Web->value
                && $appIdentifier->platform !== Platform::Web
                && ($appIdentifier->credentials()->exists() || $appIdentifier->profiles()->exists())) {
                $validator->errors()->add(
                    'platform',
                    'Detach all credentials before changing this identifier to Web.',
                );
            }
        });
    }

    private function normalizeWebIdentifier(): void
    {
        $platform = $this->input(
            'platform',
            $this->route('appIdentifier')?->platform?->value,
        );

        if ($platform === Platform::Web->value && is_string($this->input('identifier'))) {
            $this->merge(['identifier' => strtolower(trim($this->input('identifier')))]);
        }
    }
}
