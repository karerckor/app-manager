<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class CredentialUniqueness
{
    public function fingerprintUpload(
        CredentialType $type,
        array $payload,
        UploadedFile $file,
    ): string {
        $path = $file->getRealPath();

        if ($path === false || ($fileHash = hash_file('sha256', $path)) === false) {
            throw new RuntimeException('Unable to fingerprint the uploaded credential file.');
        }

        return $this->fingerprint($type, $payload, $fileHash);
    }

    public function fingerprintContents(
        CredentialType $type,
        array $payload,
        string $contents,
    ): string {
        return $this->fingerprint(
            $type,
            $payload,
            hash('sha256', $contents),
        );
    }

    public function findMatching(
        Team $team,
        CredentialType $type,
        string $fingerprint,
    ): ?Credential {
        $match = $team->credentials()
            ->where('type', $type->value)
            ->where('fingerprint', $fingerprint)
            ->oldest()
            ->first();

        if ($match) {
            return $match;
        }

        $candidates = $team->credentials()
            ->where('type', $type->value)
            ->whereNull('fingerprint')
            ->with('media')
            ->oldest()
            ->get();

        foreach ($candidates as $candidate) {
            try {
                $actualFingerprint = $this->fingerprintCredential($candidate);
            } catch (Throwable) {
                continue;
            }

            if ($candidate->fingerprint !== $actualFingerprint) {
                $candidate->forceFill(['fingerprint' => $actualFingerprint])->saveQuietly();
            }

            if ($actualFingerprint !== null && hash_equals($fingerprint, $actualFingerprint)) {
                return $candidate;
            }
        }

        return null;
    }

    public function findMatchingVaultReference(
        Team $team,
        CredentialType $type,
        VaultProvider $provider,
        string $resource,
    ): ?Credential {
        return $team->credentials()
            ->where('type', $type->value)
            ->where('storage_mode', CredentialStorageMode::External->value)
            ->whereHas('vaultReference', fn (Builder $query) => $query
                ->where('provider', $provider->value)
                ->where('resource', $resource))
            ->oldest()
            ->first();
    }

    public function fingerprintCredential(Credential $credential): ?string
    {
        $media = $credential->getFirstMedia('file');

        if (! $media) {
            return null;
        }

        $fileHash = hash('sha256', CredentialFileStorage::contents($media));

        return $this->fingerprint(
            $credential->type,
            $credential->payload ?? [],
            $fileHash,
        );
    }

    private function fingerprint(
        CredentialType $type,
        array $payload,
        string $fileHash,
    ): string {
        $identityPayload = collect(CredentialSchema::definition($type)['fields'])
            ->reject(fn (array $field) => $field['input'] === 'password')
            ->mapWithKeys(fn (array $field) => [
                $field['name'] => (string) ($payload[$field['name']] ?? ''),
            ])
            ->sortKeys()
            ->all();

        return hash('sha256', json_encode([
            'type' => $type->value,
            'file_sha256' => $fileHash,
            'identity' => $identityPayload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
