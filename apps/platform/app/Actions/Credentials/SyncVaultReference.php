<?php

namespace App\Actions\Credentials;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\CredentialVaultReference;

class SyncVaultReference
{
    /**
     * @param  array{provider: string, resource: string, metadata?: array<string, string|null>|null}  $vault
     */
    public function create(
        Credential $credential,
        array $vault,
        VaultMetadataSource $source,
    ): CredentialVaultReference {
        return CredentialVaultReference::create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'provider' => VaultProvider::from($vault['provider']),
            'resource' => $vault['resource'],
            'metadata' => $vault['metadata'] ?? null,
            'metadata_version' => 1,
            'metadata_source' => $source,
            'verification_status' => CredentialVerificationStatus::Unverified,
        ]);
    }

    /**
     * Rewrites the address and the storage metadata. Verification state is
     * deliberately preserved: a pipeline's report about this credential is
     * still the last thing we actually know, and clearing it on a rename
     * would erase real information.
     *
     * @param  array{provider: string, resource: string, metadata?: array<string, string|null>|null}  $vault
     */
    public function update(
        CredentialVaultReference $reference,
        array $vault,
        VaultMetadataSource $source,
    ): CredentialVaultReference {
        $metadata = $vault['metadata'] ?? null;
        $metadataChanged = $this->normalize($metadata) !== $this->normalize($reference->metadata);

        $reference->update([
            'provider' => VaultProvider::from($vault['provider']),
            'resource' => $vault['resource'],
            'metadata' => $metadata,
            'metadata_version' => $metadataChanged
                ? $reference->metadata_version + 1
                : $reference->metadata_version,
            'metadata_source' => $metadataChanged ? $source : $reference->metadata_source,
        ]);

        return $reference;
    }

    /**
     * Normalises metadata for change comparison only: null and an empty
     * array are the same "no metadata" state, and key order must not affect
     * whether the metadata is considered changed. The stored value is left
     * untouched — only this comparison copy is sorted.
     *
     * @param  array<string, string|null>|null  $metadata
     * @return array<string, string|null>
     */
    private function normalize(?array $metadata): array
    {
        $normalized = $metadata ?? [];
        ksort($normalized);

        return $normalized;
    }
}
