<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Credentials\RecordVaultVerification;
use App\Enums\CredentialVerificationStatus;
use App\Http\Concerns\ResolvesTeamCredential;
use App\Http\Controllers\Controller;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CredentialVerificationController extends Controller
{
    use ResolvesTeamCredential;

    public function __construct(
        private readonly RecordVaultVerification $recordVerification,
    ) {}

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $credential,
    ): JsonResponse {
        $credential = $this->resolveTeamCredential($team, $mobileApp, $credential);

        if (! $credential->isExternal()) {
            throw ValidationException::withMessages([
                'status' => 'Only a credential stored in your own vault can be verified.',
            ]);
        }

        abort_unless($credential->vaultReference, 404);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    CredentialVerificationStatus::Verified->value,
                    CredentialVerificationStatus::Failed->value,
                ]),
            ],
            'error' => [
                Rule::requiredIf($request->input('status') === CredentialVerificationStatus::Failed->value),
                Rule::prohibitedIf($request->input('status') === CredentialVerificationStatus::Verified->value),
                'string',
                'max:1000',
            ],
            'expires_at' => [
                'nullable',
                'date',
                Rule::prohibitedIf($request->input('status') === CredentialVerificationStatus::Failed->value),
            ],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:500'],
        ]);

        $reference = ($this->recordVerification)($team, $credential, $data);

        return response()->json(['data' => [
            'provider' => $reference->provider->value,
            'resource' => $reference->resource,
            'metadata' => $reference->metadata,
            'metadata_version' => $reference->metadata_version,
            'metadata_source' => $reference->metadata_source->value,
            'verification' => [
                'status' => $reference->verification_status->value,
                'verified_at' => $reference->verified_at,
                'error' => $reference->verification_error,
            ],
        ]]);
    }
}
