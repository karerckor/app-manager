<?php

namespace App\Http\Requests;

use App\Enums\ProfileType;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential_id' => [
                'required',
                Rule::exists('credentials', 'id')->where('team_id', $this->route('team')->id),
            ],
            'profile_type' => [
                Rule::requiredIf(fn () => $this->requiresProfile()),
                Rule::prohibitedIf(fn () => ! $this->requiresProfile()),
                Rule::enum(ProfileType::class),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->hasAny(['credential_id', 'profile_type'])) {
                return;
            }

            $credential = $this->route('team')->credentials()->find($this->input('credential_id'));
            if (! $credential) {
                return;
            }

            $appIdentifier = $this->route('appIdentifier');

            if (! CredentialSchema::supportsPlatform($credential->type, $appIdentifier->platform->value)) {
                $validator->errors()->add(
                    'credential_id',
                    'This credential cannot be attached to the selected platform.',
                );
            }

            if ($this->has('profile_type')) {
                $profileType = ProfileType::from($this->input('profile_type'));
                if (! BuildProfileSchema::supportsPlatform($profileType, $appIdentifier->platform)) {
                    $validator->errors()->add(
                        'profile_type',
                        'This build target cannot be used with the selected platform.',
                    );
                }
            }
        });
    }

    private function requiresProfile(): bool
    {
        $credentialId = $this->input('credential_id');
        if (! $credentialId) {
            return false;
        }

        $credential = $this->route('team')->credentials()->find($credentialId);

        return $credential
            ? CredentialSchema::requiresProfile($credential->type)
            : false;
    }
}
