<?php

namespace App\Integrations\Expo;

final class ExpoIdentifierDiscovery
{
    /**
     * @param  array<string, mixed>  $project
     * @return array{
     *     ios: list<string>,
     *     android: list<string>,
     *     defaults: array{ios: ?string, android: ?string}
     * }
     */
    public function discover(array $project): array
    {
        $identifiers = ['ios' => [], 'android' => []];
        $authoritative = ['ios' => [], 'android' => []];

        foreach ((array) ($project['androidAppCredentials'] ?? []) as $credentials) {
            $this->add(
                $identifiers['android'],
                $authoritative['android'],
                $credentials['applicationIdentifier'] ?? null,
                true,
            );
        }

        foreach ((array) ($project['iosAppCredentials'] ?? []) as $credentials) {
            $this->add(
                $identifiers['ios'],
                $authoritative['ios'],
                data_get($credentials, 'appleAppIdentifier.bundleIdentifier'),
                true,
            );
        }

        foreach ((array) ($project['builds'] ?? []) as $build) {
            $platform = match (strtoupper((string) ($build['platform'] ?? ''))) {
                'IOS' => 'ios',
                'ANDROID' => 'android',
                default => null,
            };

            if ($platform !== null) {
                $this->add(
                    $identifiers[$platform],
                    $authoritative[$platform],
                    $build['appIdentifier'] ?? null,
                    false,
                );
            }
        }

        return [
            ...$identifiers,
            'defaults' => [
                'ios' => $this->default($identifiers['ios'], $authoritative['ios']),
                'android' => $this->default($identifiers['android'], $authoritative['android']),
            ],
        ];
    }

    /** @param list<string> $identifiers @param list<string> $authoritative */
    private function add(
        array &$identifiers,
        array &$authoritative,
        mixed $identifier,
        bool $isAuthoritative,
    ): void {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return;
        }

        if (! in_array($identifier, $identifiers, true)) {
            $identifiers[] = $identifier;
        }

        if ($isAuthoritative && ! in_array($identifier, $authoritative, true)) {
            $authoritative[] = $identifier;
        }
    }

    /** @param list<string> $identifiers @param list<string> $authoritative */
    private function default(array $identifiers, array $authoritative): ?string
    {
        if (count($authoritative) === 1) {
            return $authoritative[0];
        }

        return count($identifiers) === 1 ? $identifiers[0] : null;
    }
}
