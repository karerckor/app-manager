<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\TeamPermission;
use App\Enums\VaultProvider;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\ExpoAppContext;
use App\Support\Apps\IdentifierPresenter;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use App\Support\Credentials\StorageModePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AppIdentifierPageController extends Controller
{
    public function index(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expoClient,
    ): Response {
        $mobileApp->load([
            'appIdentifiers' => fn ($q) => $q
                ->withCount('credentials')
                ->orderBy('platform')->orderBy('name'),
        ]);
        $expo = $this->expoIdentifiers($request, $team, $mobileApp, $expoClient);
        $remoteByKey = collect($expo['identifiers'])->keyBy(
            fn (array $identifier) => $this->identifierKey(
                $identifier['platform'],
                $identifier['identifier'],
            ),
        );
        $identifiers = $mobileApp->appIdentifiers
            ->map(function (AppIdentifier $identifier) use ($expo, $remoteByKey, $team, $mobileApp) {
                $key = $this->identifierKey(
                    $identifier->platform->value,
                    $identifier->identifier,
                );
                $onExpo = $remoteByKey->has($key);

                return [
                    'key' => 'database:'.$identifier->id,
                    'id' => $identifier->id,
                    'name' => $identifier->name,
                    'platform' => $identifier->platform->value,
                    'identifier' => $identifier->identifier,
                    'version' => $identifier->version,
                    'version_code' => $identifier->version_code,
                    'build_number' => $identifier->build_number,
                    'is_default' => $identifier->is_default,
                    'credentials_count' => $identifier->platform === Platform::Web
                        ? 0
                        : $identifier->credentials_count,
                    'sources' => [
                        'database' => true,
                        'expo' => $onExpo,
                    ],
                    'sync_status' => match (true) {
                        $identifier->platform === Platform::Web => 'database',
                        ! $expo['linked'] => 'database',
                        ! $expo['comparison_available'] => 'unknown',
                        $onExpo => 'synced',
                        default => 'database_only',
                    },
                    'show_url' => route('accounts.apps.identifiers.show', [$team, $mobileApp, $identifier]),
                    'update_url' => route('accounts.apps.identifiers.update', [$team, $mobileApp, $identifier]),
                    'delete_url' => route('accounts.apps.identifiers.destroy', [$team, $mobileApp, $identifier]),
                ];
            });

        if ($expo['comparison_available']) {
            $localKeys = $mobileApp->appIdentifiers
                ->mapWithKeys(fn (AppIdentifier $identifier) => [
                    $this->identifierKey(
                        $identifier->platform->value,
                        $identifier->identifier,
                    ) => true,
                ]);

            $identifiers = $identifiers->concat(
                collect($expo['identifiers'])
                    ->reject(fn (array $identifier) => $localKeys->has(
                        $this->identifierKey(
                            $identifier['platform'],
                            $identifier['identifier'],
                        ),
                    ))
                    ->map(fn (array $identifier) => [
                        'key' => 'expo:'.$this->identifierKey(
                            $identifier['platform'],
                            $identifier['identifier'],
                        ),
                        'id' => null,
                        'name' => null,
                        'platform' => $identifier['platform'],
                        'identifier' => $identifier['identifier'],
                        'version' => null,
                        'version_code' => null,
                        'build_number' => null,
                        'is_default' => false,
                        'credentials_count' => null,
                        'sources' => [
                            'database' => false,
                            'expo' => true,
                        ],
                        'sync_status' => 'expo_only',
                        'show_url' => null,
                        'update_url' => null,
                        'delete_url' => null,
                    ]),
            );
        }

        return Inertia::render('Apps/Identifiers/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'identifiers' => $identifiers
                ->sortBy([
                    ['platform', 'asc'],
                    ['identifier', 'asc'],
                ])
                ->values(),
            'expoIdentifiers' => collect($expo)->except('identifiers')->all(),
        ]);
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): Response
    {
        $appIdentifier->load(['credentials.media', 'profiles.credentials.media']);
        $supportsCredentials = $appIdentifier->platform !== Platform::Web;

        return Inertia::render('Apps/Identifiers/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'credentialTypes' => $supportsCredentials
                ? CredentialSchema::definitions()
                : [],
            'buildProfiles' => $supportsCredentials
                ? BuildProfileSchema::definitions()
                : [],
            'identifier' => IdentifierPresenter::present($team, $mobileApp, $appIdentifier),
            'availableCredentials' => $supportsCredentials
                ? $this->availableCredentials($team, $appIdentifier)
                : [],
            'checkUniquenessByDefault' => $team->check_credential_uniqueness_by_default,
            'vaultProviders' => VaultProvider::definitions(),
            'allowedStorageModes' => app(StorageModePolicy::class)->allowedValuesFor($team),
        ]);
    }

    public function sync(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expoClient,
        ExpoCredentialExporter $exporter,
        BlueprintComplianceEvaluator $blueprints,
    ): RedirectResponse {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );

        $project = $this->linkedExpoProject($mobileApp);
        if (! $project
            || ! $project->exists_on_expo
            || $project->integration->status !== IntegrationStatus::Active) {
            throw ValidationException::withMessages([
                'expo' => 'Connect an active Expo project before syncing identifiers.',
            ]);
        }

        try {
            $remote = $expoClient->projectIdentifiers(
                $project->integration,
                $project->external_id,
            );
            if (blank($remote['id'] ?? null)) {
                throw new RuntimeException('Expo did not return the linked project.');
            }
        } catch (ExpoGraphqlException|RuntimeException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        $remoteIdentifiers = $this->remoteIdentifierCandidates($remote);
        $imported = DB::transaction(function () use ($mobileApp, $remoteIdentifiers): int {
            $imported = 0;

            foreach ($remoteIdentifiers as $candidate) {
                $identifier = $mobileApp->appIdentifiers()->firstOrNew([
                    'platform' => $candidate['platform'],
                    'identifier' => $candidate['identifier'],
                ]);
                if (! $identifier->exists) {
                    $identifier->name = $candidate['platform'] === Platform::Ios->value
                        ? 'iOS'
                        : 'Android';
                    $identifier->save();
                    $imported++;
                }
            }

            return $imported;
        });

        $remoteKeys = $remoteIdentifiers->mapWithKeys(fn (array $identifier) => [
            $this->identifierKey(
                $identifier['platform'],
                $identifier['identifier'],
            ) => true,
        ]);
        $pushed = 0;
        $errors = [];
        $mobileApp->load('appIdentifiers');
        $blueprints->evaluate($mobileApp);

        foreach ($mobileApp->appIdentifiers as $identifier) {
            if ($identifier->platform === Platform::Web) {
                continue;
            }

            $key = $this->identifierKey(
                $identifier->platform->value,
                $identifier->identifier,
            );
            if ($remoteKeys->has($key)) {
                continue;
            }

            try {
                $exporter->provisionIdentifier($project, $identifier);
                $pushed++;
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $errors[] = "{$identifier->identifier}: {$exception->getMessage()}";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'expo' => 'Identifiers were partially synced. '.implode(' ', $errors),
            ]);
        }

        return back()->with(
            'status',
            "Identifiers synced: {$imported} imported from Expo, {$pushed} pushed to Expo.",
        );
    }

    private function availableCredentials(Team $team, AppIdentifier $identifier): Collection
    {
        $compatibleTypes = collect(CredentialType::cases())
            ->filter(fn (CredentialType $type) => CredentialSchema::supportsPlatform(
                $type,
                $identifier->platform->value,
            ))
            ->map(fn (CredentialType $type) => $type->value);

        return $team->credentials()
            ->with(['media', 'appIdentifiers', 'profiles'])
            ->whereIn('type', $compatibleTypes)
            ->latest('updated_at')
            ->get()
            ->map(function (Credential $credential) use ($identifier) {
                $definition = CredentialSchema::definition($credential->type);
                $attachedProfileTypes = $credential->profiles
                    ->where('app_identifier_id', $identifier->id)
                    ->pluck('type')
                    ->map(fn ($type) => $type->value)
                    ->values();
                $availableProfileTypes = collect($definition['profiles'])->pluck('value');
                $alreadyAttached = $definition['attachment'] === 'profile'
                    ? $availableProfileTypes->diff($attachedProfileTypes)->isEmpty()
                    : $credential->appIdentifiers->contains('id', $identifier->id);

                if ($alreadyAttached) {
                    return null;
                }

                $media = $credential->getFirstMedia('file');

                return [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'type' => $credential->type->value,
                    'type_label' => $definition['label'],
                    'attachment' => $definition['attachment'],
                    'expires_at' => $credential->expires_at,
                    'file_name' => $media?->file_name,
                    'attachments_count' => $credential->appIdentifiers->count()
                        + $credential->profiles->count(),
                    'attached_profile_types' => $attachedProfileTypes,
                ];
            })
            ->filter()
            ->values();
    }

    /** @return array<string, mixed> */
    private function expoIdentifiers(
        Request $request,
        Team $team,
        MobileApp $app,
        ExpoClient $expoClient,
    ): array {
        $result = [
            'linked' => false,
            'comparison_available' => false,
            'identifiers' => [],
            'error' => null,
            'sync_url' => null,
            'can_sync' => false,
        ];

        if (! ExpoAppContext::available($team)) {
            return $result;
        }

        $project = $this->linkedExpoProject($app);
        if (! $project) {
            return $result;
        }

        $result['linked'] = true;
        $result['can_sync'] = $project->integration->status === IntegrationStatus::Active
            && $project->exists_on_expo
            && $team->allows($request->user(), TeamPermission::ManageApplications)
            && $team->allows($request->user(), TeamPermission::ManageCredentials);
        $result['sync_url'] = $result['can_sync']
            ? route('accounts.apps.identifiers.sync', [$team, $app])
            : null;

        if (! $project->exists_on_expo) {
            $result['error'] = 'The linked project was not found on Expo during the last account sync.';

            return $result;
        }

        if ($project->integration->status !== IntegrationStatus::Active) {
            $result['error'] = 'Reconnect the Expo account to compare and sync identifiers.';

            return $result;
        }

        try {
            $remote = $expoClient->projectIdentifiers(
                $project->integration,
                $project->external_id,
            );
        } catch (ExpoGraphqlException $exception) {
            $result['error'] = $exception->getMessage();

            return $result;
        }

        if (blank($remote['id'] ?? null)) {
            $result['error'] = 'Expo did not return the linked project, so identifiers could not be compared.';

            return $result;
        }

        $result['comparison_available'] = true;
        $result['identifiers'] = $this->remoteIdentifierCandidates($remote)->all();

        return $result;
    }

    /** @return Collection<int, array{platform: string, identifier: string}> */
    private function remoteIdentifierCandidates(array $remote): Collection
    {
        $identifiers = collect();

        foreach ((array) ($remote['androidAppCredentials'] ?? []) as $credentials) {
            $identifier = trim((string) ($credentials['applicationIdentifier'] ?? ''));
            if ($identifier !== '') {
                $identifiers->push([
                    'platform' => Platform::Android->value,
                    'identifier' => $identifier,
                ]);
            }
        }
        foreach ((array) ($remote['iosAppCredentials'] ?? []) as $credentials) {
            $identifier = trim((string) data_get(
                $credentials,
                'appleAppIdentifier.bundleIdentifier',
                '',
            ));
            if ($identifier !== '') {
                $identifiers->push([
                    'platform' => Platform::Ios->value,
                    'identifier' => $identifier,
                ]);
            }
        }

        return $identifiers
            ->unique(fn (array $identifier) => $this->identifierKey(
                $identifier['platform'],
                $identifier['identifier'],
            ))
            ->values();
    }

    private function linkedExpoProject(MobileApp $app): ?ExpoProject
    {
        return $app->expoProject()
            ->whereHas('integration', fn ($query) => $query->connectedExpo())
            ->with('integration')
            ->first();
    }

    private function identifierKey(string $platform, string $identifier): string
    {
        return $platform.'|'.$identifier;
    }
}
