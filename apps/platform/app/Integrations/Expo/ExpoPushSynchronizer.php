<?php

namespace App\Integrations\Expo;

use App\Enums\Platform;
use App\Models\Credential;
use App\Models\ExpoCredentialLink;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class ExpoPushSynchronizer
{
    public function __construct(
        private readonly ExpoClient $expo,
        private readonly ExpoCredentialExporter $credentials,
    ) {}

    /** @return array{metadata: bool, icon: bool, identifiers: int, credentials: int} */
    public function push(ExpoProject $project): array
    {
        $project->loadMissing(['integration', 'mobileApp']);
        $app = $project->mobileApp;
        if (! $app) {
            throw new RuntimeException('The Expo project is not linked to an application.');
        }

        $app->load([
            'media',
            'appIdentifiers.credentials.media',
            'appIdentifiers.profiles.credentials.media',
        ]);
        $snapshot = array_replace_recursive([
            'version' => 1,
            'metadata' => null,
            'icon' => null,
            'identifiers' => [],
            'credentials' => [],
        ], (array) $project->auto_sync_snapshot);
        $result = [
            'metadata' => false,
            'icon' => false,
            'identifiers' => 0,
            'credentials' => 0,
        ];

        $metadataSignature = $this->signature(['name' => $app->name]);
        if (($snapshot['metadata']['signature'] ?? null) !== $metadataSignature) {
            $remote = $this->expo->updateProjectInfo(
                $project->integration,
                $project->external_id,
                $app->name,
            );
            $snapshot['metadata'] = ['signature' => $metadataSignature];
            $project->name = (string) ($remote['name'] ?? $app->name);
            $result['metadata'] = true;
            $this->persistSnapshot($project, $snapshot);
        }

        $icon = $app->getFirstMedia('app_icon');
        $iconSignature = $icon ? $this->signature([
            'uuid' => $icon->uuid,
            'size' => $icon->size,
            'mime_type' => $icon->mime_type,
            'updated_at' => $icon->updated_at?->toIso8601String(),
        ]) : null;
        $previousIconSignature = data_get($snapshot, 'icon.signature');
        $hasIconSnapshot = is_array($snapshot['icon']);
        $iconChanged = $hasIconSnapshot
            ? $previousIconSignature !== $iconSignature
            : $icon !== null || $project->icon_url !== null;

        if ($iconChanged) {
            if ($icon) {
                $contents = Storage::disk($icon->disk)->get($icon->getPathRelativeToRoot());
                $this->expo->uploadProjectIcon(
                    $project->integration,
                    $project->external_id,
                    $contents,
                    $icon->mime_type ?: 'image/png',
                );
            } else {
                $this->expo->removeProjectIcon($project->integration, $project->external_id);
            }

            $remote = $this->expo->projectSummary(
                $project->integration,
                $project->external_id,
            );
            $project->icon_url = $remote['profileImageUrl'] ?? null;
            $snapshot['icon'] = ['signature' => $iconSignature];
            $result['icon'] = true;
            $this->persistSnapshot($project, $snapshot);
        } elseif (! is_array($snapshot['icon'])) {
            $snapshot['icon'] = ['signature' => $iconSignature];
        }

        $identifierSnapshot = [];
        $errors = [];
        foreach ($app->appIdentifiers as $identifier) {
            if ($identifier->platform === Platform::Web) {
                continue;
            }

            $signature = $this->signature([
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
            ]);
            $previous = (array) ($snapshot['identifiers'][$identifier->id] ?? []);
            if (($previous['signature'] ?? null) === $signature) {
                $identifierSnapshot[$identifier->id] = $previous;

                continue;
            }

            try {
                $remote = $this->credentials->provisionIdentifier($project, $identifier);
                $identifierSnapshot[$identifier->id] = [
                    'signature' => $signature,
                    'remote_type' => $remote['type'],
                    'remote_id' => $remote['id'],
                ];
                $result['identifiers']++;
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $errors[] = "{$identifier->identifier}: {$exception->getMessage()}";
                if ($previous !== []) {
                    $identifierSnapshot[$identifier->id] = $previous;
                }
            }
        }
        $snapshot['identifiers'] = $identifierSnapshot;
        $this->persistSnapshot($project, $snapshot);

        $links = $project->credentialLinks()->get()->keyBy(
            fn (ExpoCredentialLink $link) => $this->credentialKey(
                $link->app_identifier_id,
                $link->credential_id,
            ),
        );
        $credentialSnapshot = [];
        foreach ($this->credentialCandidates($app) as $key => $candidate) {
            /** @var Credential $credential */
            $credential = $candidate['credential'];
            if (! ExpoCredentialExporter::supports($credential->type)
                || $credential->getFirstMedia('file') === null) {
                continue;
            }

            $signature = $this->signature([
                'identifier' => [
                    'id' => $candidate['identifier']->id,
                    'platform' => $candidate['identifier']->platform->value,
                    'value' => $candidate['identifier']->identifier,
                ],
                'credential' => [
                    'id' => $credential->id,
                    'type' => $credential->type->value,
                    'name' => $credential->name,
                    'payload' => $credential->payload,
                    'expires_at' => $credential->expires_at?->toIso8601String(),
                    'updated_at' => $credential->updated_at?->toIso8601String(),
                    'file' => $this->credentialFileSignature($credential),
                ],
                'targets' => $candidate['targets'],
            ]);
            $previous = (array) ($snapshot['credentials'][$key] ?? []);
            $link = $links->get($key);

            if (($previous['signature'] ?? null) === $signature) {
                $credentialSnapshot[$key] = $previous;

                continue;
            }

            if ($link && $this->linkMatchesCurrentLocalState(
                $link,
                $candidate['identifier']->updated_at,
                $credential,
                $signature,
            )) {
                $credentialSnapshot[$key] = $this->credentialLinkSnapshot($link, $signature);

                continue;
            }

            try {
                $export = $this->credentials->export(
                    $project,
                    $candidate['identifier'],
                    $credential,
                );
                /** @var ExpoCredentialLink $link */
                $link = $export['link'];
                $link->update([
                    'metadata' => [
                        ...($link->metadata ?? []),
                        'source_signature' => $signature,
                    ],
                ]);
                $credentialSnapshot[$key] = $this->credentialLinkSnapshot(
                    $link->refresh(),
                    $signature,
                );
                $result['credentials']++;
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $errors[] = "{$credential->name}: {$exception->getMessage()}";
                if ($previous !== []) {
                    $credentialSnapshot[$key] = $previous;
                }
            }
        }
        $snapshot['credentials'] = $credentialSnapshot;
        $this->persistSnapshot($project, $snapshot);

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        return $result;
    }

    /** @return array<string, array{identifier: mixed, credential: Credential, targets: array<int, string>}> */
    private function credentialCandidates(MobileApp $app): array
    {
        $candidates = [];

        foreach ($app->appIdentifiers as $identifier) {
            if ($identifier->platform === Platform::Web) {
                continue;
            }

            foreach ($identifier->credentials as $credential) {
                $key = $this->credentialKey($identifier->id, $credential->id);
                $candidates[$key] = [
                    'identifier' => $identifier,
                    'credential' => $credential,
                    'targets' => ['direct'],
                ];
            }

            foreach ($identifier->profiles as $profile) {
                foreach ($profile->credentials as $credential) {
                    $key = $this->credentialKey($identifier->id, $credential->id);
                    $candidate = $candidates[$key] ?? [
                        'identifier' => $identifier,
                        'credential' => $credential,
                        'targets' => [],
                    ];
                    $candidate['targets'][] = 'profile:'.$profile->type->value;
                    $candidate['targets'] = array_values(array_unique($candidate['targets']));
                    sort($candidate['targets']);
                    $candidates[$key] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /** @return array<string, mixed>|null */
    private function credentialFileSignature(Credential $credential): ?array
    {
        $media = $credential->getFirstMedia('file');

        return $media ? [
            'uuid' => $media->uuid,
            'size' => $media->size,
            'mime_type' => $media->mime_type,
            'updated_at' => $media->updated_at?->toIso8601String(),
        ] : null;
    }

    /** @return array<string, mixed> */
    private function credentialLinkSnapshot(ExpoCredentialLink $link, string $signature): array
    {
        return [
            'signature' => $signature,
            'remote_type' => $link->remote_resource_type,
            'remote_id' => $link->remote_resource_id,
            'metadata' => Arr::except($link->metadata ?? [], ['source_signature']),
        ];
    }

    private function linkMatchesCurrentLocalState(
        ExpoCredentialLink $link,
        ?CarbonInterface $identifierUpdatedAt,
        Credential $credential,
        string $signature,
    ): bool {
        if (($link->metadata['source_signature'] ?? null) === $signature) {
            return true;
        }

        if (! $link->last_synced_at) {
            return false;
        }

        $mediaUpdatedAt = $credential->getFirstMedia('file')?->updated_at;
        $latestLocalTimestamp = collect([
            $identifierUpdatedAt,
            $credential->updated_at,
            $mediaUpdatedAt,
        ])->filter()
            ->map(fn (CarbonInterface $date): int => $date->getTimestamp())
            ->max();

        return $latestLocalTimestamp === null
            || $link->last_synced_at->getTimestamp() >= $latestLocalTimestamp;
    }

    private function credentialKey(string $identifierId, string $credentialId): string
    {
        return $identifierId.':'.$credentialId;
    }

    /** @param array<string, mixed> $snapshot */
    private function persistSnapshot(ExpoProject $project, array $snapshot): void
    {
        $project->forceFill(['auto_sync_snapshot' => $snapshot])->saveQuietly();
    }

    /** @param array<string, mixed> $value */
    private function signature(array $value): string
    {
        try {
            return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException('Automatic Expo sync could not fingerprint local data.', 0, $exception);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
