<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Rules\ValidDomainName;
use Illuminate\Validation\Rule;

trait ValidatesAppIdentifier
{
    protected function identifierRules(
        MobileApp $mobileApp,
        ?AppIdentifier $appIdentifier = null,
        bool $update = false,
        bool $createMobilePair = false,
    ): array {
        $required = $update ? 'sometimes' : 'required';
        $platformValue = request()->input('platform', $appIdentifier?->platform->value);

        $identifierRequired = $update && request()->has('platform') && ! request()->has('identifier')
            ? 'sometimes'
            : $required;

        return [
            'platform' => [$required, Rule::enum(Platform::class)],
            'identifier' => [
                $identifierRequired,
                'string',
                'max:255',
                $platformValue === Platform::Web->value
                    ? new ValidDomainName
                    : 'regex:/^[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)+$/',
                Rule::unique('app_identifiers')->where(fn ($query) => $query
                    ->where('mobile_app_id', $mobileApp->id)
                    ->when(
                        $createMobilePair,
                        fn ($query) => $query->whereIn('platform', [
                            Platform::Ios->value,
                            Platform::Android->value,
                        ]),
                        fn ($query) => $query->where('platform', $platformValue),
                    ))->ignore($appIdentifier?->id),
            ],
            'name' => [$required === 'required' ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:255'],
            'version' => [$required === 'required' ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:255'],
            'version_code' => [
                $required === 'required' ? 'nullable' : 'sometimes',
                'nullable',
                Rule::prohibitedIf(! $createMobilePair && $platformValue !== Platform::Android->value),
                'integer',
                'min:1',
            ],
            'build_number' => [
                $required === 'required' ? 'nullable' : 'sometimes',
                'nullable',
                Rule::prohibitedIf(! $createMobilePair && $platformValue !== Platform::Ios->value),
                'string',
                'max:255',
            ],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
