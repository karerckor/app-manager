<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use App\Http\Requests\Concerns\ValidatesAppIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppIdentifierRequest extends FormRequest
{
    use ValidatesAppIdentifier;

    public function authorize(): bool
    {
        return true;
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
        $appIdentifier = $this->route('appIdentifier');

        if ($this->has('platform') && ! $this->has('identifier')) {
            $this->merge(['identifier' => $appIdentifier->identifier]);
        }

        if ($this->input('platform') === 'web' && is_string($this->input('identifier'))) {
            $this->merge(['identifier' => strtolower(trim($this->input('identifier')))]);
        }
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
}
