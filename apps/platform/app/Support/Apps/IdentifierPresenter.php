<?php

namespace App\Support\Apps;

use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;

class IdentifierPresenter
{
    public static function present(Team $team, MobileApp $app, AppIdentifier $identifier): array
    {
        return [
            'id' => $identifier->id,
            'name' => $identifier->name,
            'platform' => $identifier->platform->value,
            'identifier' => $identifier->identifier,
            'version' => $identifier->version,
            'version_code' => $identifier->version_code,
            'update_url' => route('accounts.apps.identifiers.update', [
                'team' => $team,
                'mobileApp' => $app,
                'appIdentifier' => $identifier,
            ]),
            'delete_url' => route('accounts.apps.identifiers.destroy', [
                'team' => $team,
                'mobileApp' => $app,
                'appIdentifier' => $identifier,
            ]),
            'credentials_count' => $identifier->credentials->count()
                + $identifier->profiles->sum(fn ($profile) => $profile->credentials->count()),
            'services' => $identifier->credentials
                ->map(fn ($credential) => CredentialPresenter::present($team, $credential, $identifier, null))
                ->values(),
            'build_targets' => $identifier->profiles
                ->map(function ($profile) use ($team, $identifier) {
                    $definition = BuildProfileSchema::definition($profile->type, $identifier->platform) ?? [];

                    return [
                        'type' => $profile->type->value,
                        'label' => $definition['label'] ?? $profile->name,
                        'artifact' => $definition['artifact'] ?? null,
                        'description' => $definition['description'] ?? null,
                        'credentials' => $profile->credentials
                            ->map(fn ($credential) => CredentialPresenter::present($team, $credential, $identifier, $profile))
                            ->values(),
                    ];
                })
                ->values(),
        ];
    }
}
