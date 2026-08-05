<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Support\Credentials\CredentialLifecycle;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CredentialVaultController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        $this->authorize('view', $team);

        $targets = $team->mobileApps()
            ->with(['appIdentifiers' => fn ($query) => $query->orderBy('platform')->orderByDesc('is_default')->orderBy('identifier')])
            ->orderBy('name')
            ->get()
            ->flatMap(fn ($app) => $app->appIdentifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'app_name' => $app->name,
                'app_slug' => $app->slug,
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
                'is_default' => $identifier->is_default,
            ]))
            ->values();

        $credentials = $team->credentials()
            ->with([
                'media',
                'appIdentifiers.mobileApp',
                'profiles.appIdentifier.mobileApp',
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($credential) use ($team) {
                $definition = CredentialSchema::definition($credential->type);
                $directUsages = $credential->appIdentifiers->map(fn ($identifier) => [
                    'kind' => 'identifier',
                    'app_id' => $identifier->mobileApp->id,
                    'app_name' => $identifier->mobileApp->name,
                    'app_slug' => $identifier->mobileApp->slug,
                    'app_url' => route('accounts.apps.show', [$team, $identifier->mobileApp]),
                    'identifier' => $identifier->identifier,
                    'platform' => $identifier->platform->value,
                    'profile' => null,
                    'detach_url' => route('accounts.credentials.attachments.destroy', [
                        $team,
                        $credential,
                        'kind' => 'identifier',
                        'attachment' => $identifier,
                    ]),
                ]);
                $profileUsages = $credential->profiles->map(function ($profile) use ($team, $credential) {
                    $identifier = $profile->appIdentifier;

                    return [
                        'kind' => 'profile',
                        'app_id' => $identifier->mobileApp->id,
                        'app_name' => $identifier->mobileApp->name,
                        'app_slug' => $identifier->mobileApp->slug,
                        'app_url' => route('accounts.apps.show', [$team, $identifier->mobileApp]),
                        'identifier' => $identifier->identifier,
                        'platform' => $identifier->platform->value,
                        'profile' => $profile->type->value,
                        'detach_url' => route('accounts.credentials.attachments.destroy', [
                            $team,
                            $credential,
                            'kind' => 'profile',
                            'attachment' => $profile,
                        ]),
                    ];
                });
                $usages = $directUsages->concat($profileUsages)
                    ->sortBy(fn ($usage) => [$usage['app_name'], $usage['platform'], $usage['identifier'], $usage['profile']])
                    ->values();
                $media = $credential->getFirstMedia('file');

                return [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'type' => $credential->type->value,
                    'type_label' => $definition['label'],
                    'platforms' => $definition['platforms'],
                    'attachment' => $definition['attachment'],
                    'profiles' => $definition['profiles'],
                    'status' => CredentialLifecycle::status($credential),
                    'expires_at' => $credential->expires_at,
                    'updated_at' => $credential->updated_at,
                    'file' => $media ? [
                        'name' => $media->file_name,
                        'size' => $media->size,
                    ] : null,
                    'usages' => $usages,
                    'usage_count' => $usages->count(),
                    'attach_url' => route('accounts.credentials.attachments.store', [$team, $credential]),
                ];
            });

        return Inertia::render('Credentials/Index', [
            'credentials' => $credentials,
            'targets' => $targets,
            'stats' => [
                'total' => $credentials->count(),
                'ready' => $credentials->where('status', 'ready')->count(),
                'attention' => $credentials->whereIn('status', ['missing_file', 'expiring', 'expired'])->count(),
                'shared' => $credentials->where('usage_count', '>', 1)->count(),
            ],
        ]);
    }
}
