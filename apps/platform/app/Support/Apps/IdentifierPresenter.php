<?php

namespace App\Support\Apps;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;

class IdentifierPresenter
{
    public static function present(Team $team, MobileApp $app, AppIdentifier $identifier): array
    {
        $supportsCredentials = $identifier->platform !== Platform::Web;
        $expoProject = $supportsCredentials
            ? ExpoProject::query()
                ->with(['integration', 'credentialLinks'])
                ->where('mobile_app_id', $app->id)
                ->whereHas('integration', fn ($query) => $query->connectedExpo())
                ->first()
            : null;

        return [
            'id' => $identifier->id,
            'name' => $identifier->name,
            'platform' => $identifier->platform->value,
            'identifier' => $identifier->identifier,
            'version' => $identifier->version,
            'version_code' => $identifier->version_code,
            'supports_credentials' => $supportsCredentials,
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
            'attach_credential_url' => $supportsCredentials
                ? route('accounts.apps.identifiers.credentials.attach', [
                    'team' => $team,
                    'mobileApp' => $app,
                    'appIdentifier' => $identifier,
                ])
                : null,
            'credentials_count' => $supportsCredentials
                ? $identifier->credentials->count()
                    + $identifier->profiles->sum(fn ($profile) => $profile->credentials->count())
                : 0,
            'services' => ($supportsCredentials ? $identifier->credentials : collect())
                ->map(fn ($credential) => CredentialPresenter::present(
                    $team,
                    $credential,
                    $identifier,
                    null,
                    $expoProject,
                ))
                ->values(),
            'build_targets' => ($supportsCredentials ? $identifier->profiles : collect())
                ->map(function ($profile) use ($team, $identifier, $expoProject) {
                    $definition = BuildProfileSchema::definition($profile->type, $identifier->platform) ?? [];

                    return [
                        'type' => $profile->type->value,
                        'label' => $definition['label'] ?? $profile->name,
                        'artifact' => $definition['artifact'] ?? null,
                        'description' => $definition['description'] ?? null,
                        'credentials' => $profile->credentials
                            ->map(fn ($credential) => CredentialPresenter::present(
                                $team,
                                $credential,
                                $identifier,
                                $profile,
                                $expoProject,
                            ))
                            ->values(),
                    ];
                })
                ->values(),
        ];
    }
}
