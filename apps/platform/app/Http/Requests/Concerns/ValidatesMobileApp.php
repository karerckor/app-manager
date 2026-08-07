<?php

namespace App\Http\Requests\Concerns;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Validation\Rule;

trait ValidatesMobileApp
{
    protected function mobileAppRules(Team $team, ?MobileApp $mobileApp = null): array
    {
        $required = $mobileApp ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('mobile_apps')->where('team_id', $team->id)->ignore($mobileApp?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'blueprint_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('blueprints', 'id')->where('team_id', $team->id),
            ],
        ];
    }
}
