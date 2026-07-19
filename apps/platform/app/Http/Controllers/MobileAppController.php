<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Profile;
use App\Models\Team;
use App\Support\Apps\BuildPresenter;
use App\Support\Apps\IdentifierPresenter;
use App\Support\Apps\PipelinePresenter;
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
            'identifiers' => $mobileApp->appIdentifiers->map(
                fn ($identifier) => IdentifierPresenter::present($team, $mobileApp, $identifier)
            ),
            'credentialTypes' => CredentialSchema::definitions(),
            'buildProfiles' => BuildProfileSchema::definitions(),
            'pipelines' => $canRunPipelines ? $mobileApp->ciCdPipelines()
                ->with('integration')
                ->where('enabled', true)
                ->latest()
                ->get()
                ->filter(fn ($pipeline) => $pipeline->integration->status->canDispatch())
                ->map(fn ($pipeline) => PipelinePresenter::present($team, $pipeline))
                ->values() : collect(),
            'builds' => $mobileApp->buildRecords()
                ->with(['ciCdRun.pipeline', 'artifacts.media'])
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn ($build) => BuildPresenter::present($team, $mobileApp, $build, $canRunPipelines, $canManageArtifacts)),
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

    private function credentialData(Team $team, Credential $credential, AppIdentifier $identifier, ?Profile $profile): array
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
            'profile' => $profile ? BuildProfileSchema::definition($profile->type, $identifier->platform) : null,
            'fields' => array_keys($payload),
            'configured_fields' => array_keys($payload),
            'payload' => collect($definition['fields'])->mapWithKeys(fn ($field) => [
                $field['name'] => $field['input'] === 'password'
                    ? ''
                    : ($payload[$field['name']] ?? ''),
            ]),
            'update_url' => route('accounts.credentials.update', ['team' => $team, 'credential' => $credential]),
            'delete_url' => route('accounts.credentials.destroy', ['team' => $team, 'credential' => $credential]),
            'attached_elsewhere' => $credential->isAttachedOutsideApp($identifier->mobile_app_id),
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
