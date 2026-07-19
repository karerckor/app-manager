<?php

namespace App\Http\Controllers;

use App\Enums\BuildReportingMode;
use App\Enums\TeamPermission;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MobileAppController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

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

        $app = DB::transaction(function () use ($team, $request, $data, $slug) {
            $app = $team->mobileApps()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
            ]);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppCreated,
                $app,
                WebhookPayload::mobileApp($app),
                $request->user(),
            );

            return $app;
        });

        return redirect()->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app])->with('status', 'Application created.');
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);
        $canRunPipelines = $team->allows($request->user(), TeamPermission::RunPipelines);
        $canManageArtifacts = $team->allows($request->user(), TeamPermission::ManageApplications);

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
                'tags' => $mobileApp->tags ?? [],
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
                    'tags' => route('accounts.apps.tags.update', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'destroy' => route('accounts.apps.destroy', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'identifiers' => route('accounts.apps.identifiers.store', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'credentials' => route('accounts.apps.credentials.store', ['team' => $team, 'mobileApp' => $mobileApp]),
                    'assets' => route('accounts.apps.assets.upload', ['team' => $team, 'mobileApp' => $mobileApp, 'collection' => '__collection__']),
                    'manual_builds' => route('accounts.apps.builds.store', ['team' => $team, 'mobileApp' => $mobileApp]),
                ],
            ],
            'identifiers' => $mobileApp->appIdentifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'name' => $identifier->name,
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
                'version' => $identifier->version,
                'version_code' => $identifier->version_code,
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
            'credentialTypes' => CredentialSchema::definitions(),
            'buildProfiles' => BuildProfileSchema::definitions(),
            'pipelines' => $canRunPipelines ? $mobileApp->ciCdPipelines()
                ->with('integration')
                ->where('enabled', true)
                ->latest()
                ->get()
                ->filter(fn ($pipeline) => $pipeline->integration->status->canDispatch())
                ->map(fn ($pipeline) => [
                    'id' => $pipeline->id,
                    'name' => $pipeline->name,
                    'provider' => $pipeline->integration->provider->value,
                    'repository' => $pipeline->repository_full_name,
                    'workflow' => $pipeline->workflow_name,
                    'default_ref' => $pipeline->default_ref,
                    'profile_type' => $pipeline->profile_type->value,
                    'reporting_mode' => $pipeline->reporting_mode->value,
                    'input_schema' => $pipeline->input_schema ?? [],
                    'input_defaults' => $pipeline->input_defaults ?? [],
                    'run_url' => route('accounts.integrations.pipelines.runs.store', [$team, $pipeline->integration, $pipeline]),
                ])
                ->values() : collect(),
            'builds' => $mobileApp->buildRecords()
                ->with(['ciCdRun.pipeline', 'artifacts.media'])
                ->latest()
                ->limit(30)
                ->get()
                ->map(function ($build) use ($canRunPipelines, $canManageArtifacts, $team, $mobileApp) {
                    $run = $canRunPipelines ? $build->ciCdRun : null;

                    return [
                        'id' => $build->id,
                        'status' => $build->status->value,
                        'profile_type' => $build->profile_type->value,
                        'pipeline_name' => $run?->pipeline?->name,
                        'external_url' => $run?->external_run_url,
                        'provider_status' => $run?->provider_status,
                        'reporting_mode' => $build->reporting_mode->value,
                        'parameters' => $build->parameters ?? [],
                        'logs' => $build->log_output,
                        'logs_pending' => $build->reporting_mode->value === 'automatic'
                            && $run?->external_run_id !== null
                            && $build->logs_updated_at === null,
                        'created_at' => $build->created_at,
                        'tags' => $build->tags ?? [],
                        'tags_url' => route('accounts.apps.builds.tags.update', [$team, $mobileApp, $build]),
                        'started_at' => $build->started_at,
                        'finished_at' => $build->finished_at,
                        'can_cancel' => $canRunPipelines && $build->status->canRequestCancellation(),
                        'cancel_url' => route('accounts.apps.builds.cancel', [$team, $mobileApp, $build]),
                        'can_force_status' => $canRunPipelines
                            && $build->reporting_mode === BuildReportingMode::Manual
                            && ! $build->status->isTerminal(),
                        'force_status_url' => route('accounts.apps.builds.status.update', [$team, $mobileApp, $build]),
                        'can_manage_artifacts' => $canManageArtifacts,
                        'artifacts_url' => route('accounts.apps.builds.artifacts.store', [$team, $mobileApp, $build]),
                        'artifacts' => $build->artifacts->map(function ($artifact) use ($team, $mobileApp, $build) {
                            $media = $artifact->getFirstMedia('file');

                            return [
                                'id' => $artifact->id,
                                'name' => $artifact->name,
                                'size' => $media?->size,
                                'mime' => $media?->mime_type,
                                'metadata' => $artifact->metadata,
                                'tags' => $artifact->tags ?? [],
                                'tags_url' => route('accounts.apps.builds.artifacts.tags.update', [$team, $mobileApp, $build, $artifact]),
                                'created_at' => $artifact->created_at,
                                'download_url' => route('accounts.apps.builds.artifacts.download', [$team, $mobileApp, $build, $artifact]),
                                'delete_url' => route('accounts.apps.builds.artifacts.destroy', [$team, $mobileApp, $build, $artifact]),
                            ];
                        })->values(),
                    ];
                }),
        ]);
    }

    public function update(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($mobileApp, $team, $request, $data) {
            $mobileApp->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppUpdated,
                $mobileApp,
                WebhookPayload::mobileApp($mobileApp),
                $request->user(),
            );
        });

        return redirect()
            ->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $mobileApp])
            ->with('status', 'Application updated.');
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        DB::transaction(function () use ($mobileApp, $team, $request) {
            $payload = WebhookPayload::mobileApp($mobileApp);
            $mobileApp->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppDeleted,
                $mobileApp,
                $payload,
                $request->user(),
            );
        });

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
            'tags' => $credential->tags ?? [],
            'tags_url' => route('accounts.credentials.tags.update', ['team' => $team, 'credential' => $credential]),
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

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
