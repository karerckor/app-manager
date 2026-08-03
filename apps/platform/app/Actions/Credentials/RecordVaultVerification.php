<?php

namespace App\Actions\Credentials;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordVaultVerification
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
    ) {}

    /**
     * @param  array{status: string, error?: string|null, expires_at?: string|null, metadata?: array<string, string|null>|null}  $data
     */
    public function __invoke(Team $team, Credential $credential, array $data): CredentialVaultReference
    {
        $reference = $credential->vaultReference;
        $status = CredentialVerificationStatus::from($data['status']);
        $wasFailed = $reference->verification_status === CredentialVerificationStatus::Failed;

        return DB::transaction(function () use (
            $team,
            $credential,
            $reference,
            $status,
            $wasFailed,
            $data,
        ): CredentialVaultReference {
            $attributes = [
                'verification_status' => $status,
                'verification_error' => $status === CredentialVerificationStatus::Failed
                    ? $data['error']
                    : null,
            ];

            if ($status === CredentialVerificationStatus::Verified) {
                $attributes['verified_at'] = now();
            }

            if (array_key_exists('metadata', $data) && $data['metadata'] !== null) {
                $attributes['metadata'] = $data['metadata'];
                $attributes['metadata_version'] = $reference->metadata_version + 1;
                $attributes['metadata_source'] = VaultMetadataSource::Pipeline;
            }

            $reference->update($attributes);

            if (filled($data['expires_at'] ?? null)) {
                $credential->update(['expires_at' => Carbon::parse($data['expires_at'])]);
            }

            // Only the transition is newsworthy. A build retrying in a loop
            // would otherwise emit one webhook per attempt.
            if ($status === CredentialVerificationStatus::Failed && ! $wasFailed) {
                $this->webhookEvents->record(
                    $team,
                    WebhookEventType::CredentialVerificationFailed,
                    $credential,
                    WebhookPayload::credential($credential->fresh()),
                );
            }

            return $reference->fresh();
        });
    }
}
