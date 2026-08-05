<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MobileAppController extends Controller
{
    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('manageApplications', $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('mobile_apps')->where('team_id', $team->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $baseSlug = Str::slug(($data['slug'] ?? null) ?: $data['name']) ?: 'app';
        $slug = $baseSlug;
        $suffix = 2;

        while ($team->mobileApps()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $app = $team->mobileApps()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app])->with('status', 'Application created.');
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorize('view', $team);

        $mobileApp->load([
            'appIdentifiers' => fn ($query) => $query
                ->with(['credentials.media', 'profiles.credentials.media'])
                ->orderBy('platform')
                ->orderBy('name'),
        ]);

        $credentials = $mobileApp->appIdentifiers
            ->flatMap(function ($identifier) use ($team) {
                $direct = $identifier->credentials->map(fn ($credential) => [$credential, null]);
                $profile = $identifier->profiles->flatMap(
                    fn ($item) => $item->credentials->map(fn ($credential) => [$credential, $item]),
                );

                return $direct->concat($profile)->map(fn ($pair) => $this->credentialData($team, $pair[0], $identifier, $pair[1]));
            })
            ->unique('id')
            ->values();

        return Inertia::render('Apps/Show', [
            'app' => [
                'id' => $mobileApp->id,
                'name' => $mobileApp->name,
                'slug' => $mobileApp->slug,
                'description' => $mobileApp->description,
                'created_at' => $mobileApp->created_at,
                'updated_at' => $mobileApp->updated_at,
                'assets' => collect(['app_icon', 'splash_icon'])->mapWithKeys(function ($collection) use ($team, $mobileApp) {
                    $media = $mobileApp->getFirstMedia($collection);

                    return [$collection => $media ? [
                        'name' => $media->file_name,
                        'size' => $media->size,
                        'preview_url' => route('accounts.apps.assets.preview', ['team' => $team, 'mobileApp' => $mobileApp, 'collection' => $collection]),
                        'download_url' => route('accounts.apps.assets.download', ['team' => $team, 'mobileApp' => $mobileApp, 'collection' => $collection]),
                    ] : null];
                }),
                'urls' => [
                    'index' => route('accounts.apps.index', ['team' => $team]),
                    'update' => route('accounts.apps.update', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'destroy' => route('accounts.apps.destroy', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'identifiers' => route('accounts.apps.identifiers.store', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'credentials' => route('accounts.apps.credentials.store', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'assets' => route('accounts.apps.assets.upload', ['team' => $team, 'mobileApp' => $mobileApp, 'collection' => '__collection__']),
                ],
            ],
            'identifiers' => $mobileApp->appIdentifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'name' => $identifier->name,
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
                'version' => $identifier->version,
                'version_code' => $identifier->version_code,
                'build_number' => $identifier->build_number,
                'is_default' => $identifier->is_default,
                'update_url' => route('accounts.apps.identifiers.update', [
                    'team' => $team,
                    'mobileApp' => $mobileApp,
                    'appIdentifier' => $identifier,
                ]),
                'profiles' => $identifier->profiles->map(fn ($profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'type' => $profile->type->value,
                ]),
                'credentials_count' => $identifier->credentials->count()
                    + $identifier->profiles->sum(fn ($profile) => $profile->credentials->count()),
            ]),
            'credentials' => $credentials,
            'builds' => $mobileApp->buildRecords()
                ->withCount('logs')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($build) => [
                    'id' => $build->id,
                    'external_id' => $build->external_id,
                    'status' => $build->status->value,
                    'profile_type' => $build->profile_type->value,
                    'commit_sha' => $build->commit_sha,
                    'ci' => [
                        'provider' => $build->ci_provider,
                        'run_id' => $build->ci_run_id,
                        'run_url' => $build->ci_run_url,
                    ],
                    'artifact_url' => $build->artifact_url,
                    'target' => $build->context_snapshot['target'] ?? null,
                    'registered_at' => $build->created_at,
                    'started_at' => $build->started_at,
                    'finished_at' => $build->finished_at,
                    'logs_count' => $build->logs_count,
                    'logs_url' => route('accounts.apps.builds.logs.index', [
                        'team' => $team,
                        'mobileApp' => $mobileApp,
                        'buildRecord' => $build,
                    ]),
                    'cancel_url' => $build->status->canBeCancelled()
                        ? route('accounts.apps.builds.cancel', [
                            'team' => $team,
                            'mobileApp' => $mobileApp,
                            'buildRecord' => $build,
                        ])
                        : null,
                ]),
            'credentialTypes' => CredentialSchema::definitions(),
        ]);
    }

    public function update(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorize('manageApplications', $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $mobileApp->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return redirect()
            ->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $mobileApp])
            ->with('status', 'Application updated.');
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorize('manageApplications', $team);

        $mobileApp->delete();

        return redirect()
            ->route('accounts.apps.index', ['team' => $team])
            ->with('status', 'Application deleted.');
    }

    private function credentialData(Team $team, $credential, $identifier, $profile): array
    {
        $media = $credential->getFirstMedia('file');
        $definition = CredentialSchema::definition($credential->type);
        $payload = $credential->payload ?? [];

        return [
            'id' => $credential->id,
            'app_identifier_id' => $identifier->id,
            'name' => $credential->name,
            'type' => $credential->type->value,
            'expires_at' => $credential->expires_at,
            'updated_at' => $credential->updated_at,
            'identifier' => $identifier->identifier,
            'platform' => $identifier->platform->value,
            'profile' => $profile ? BuildProfileSchema::definition($profile->type, $identifier->platform) : null,
            'fields' => array_keys($payload),
            'configured_fields' => array_keys($payload),
            'payload' => collect($definition['fields'])->mapWithKeys(fn ($field) => [
                $field['name'] => $field['input'] === 'password'
                    ? ''
                    : ($payload[$field['name']] ?? ''),
            ]),
            'update_url' => route('accounts.credentials.update', ['team' => $team, 'credential' => $credential]),
            'upload_url' => route('accounts.credentials.upload', ['team' => $team, 'credential' => $credential]),
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'download_url' => route('accounts.credentials.download', ['team' => $team, 'credential' => $credential]),
            ] : null,
        ];
    }
}
