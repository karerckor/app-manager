<?php

namespace App\Support\Apps;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamPermission;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use Illuminate\Http\Request;

class AppContext
{
    public static function for(Request $request, Team $team, MobileApp $app): array
    {
        return [
            'app' => fn () => self::appProp($request, $team, $app),
            'pipelines' => fn () => self::pipelinesProp($request, $team, $app),
            'identifiers' => fn () => $app->appIdentifiers()
                ->orderBy('platform')->orderBy('name')->get()
                ->map(fn ($identifier) => [
                    'id' => $identifier->id,
                    'platform' => $identifier->platform->value,
                    'identifier' => $identifier->identifier,
                    'name' => $identifier->name,
                ])->values(),
            'buildProfiles' => fn () => BuildProfileSchema::definitions(),
            'tag_suggestions' => fn () => self::tagSuggestions($app),
        ];
    }

    private static function appProp(Request $request, Team $team, MobileApp $app): array
    {
        $assets = collect(['app_icon', 'splash_icon'])->mapWithKeys(function ($collection) use ($team, $app) {
            $media = $app->getFirstMedia($collection);

            return [$collection => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'preview_url' => route('accounts.apps.assets.preview', [$team, $app, $collection]),
                'download_url' => route('accounts.apps.assets.download', [$team, $app, $collection]),
            ] : null];
        });

        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'description' => $app->description,
            'metadata' => $app->metadata,
            'tags' => $app->tags ?? [],
            'created_at' => $app->created_at,
            'updated_at' => $app->updated_at,
            'assets' => $assets,
            'pipeline_configuration' => self::pipelineConfigurationProp($request, $team, $app),
            'urls' => [
                'index' => route('accounts.apps.index', [$team]),
                'overview' => route('accounts.apps.show', [$team, $app]),
                'media_page' => route('accounts.apps.media.index', [$team, $app]),
                'media_regenerate' => route('accounts.apps.media.regenerate', [$team, $app]),
                'identifiers_page' => route('accounts.apps.identifiers.index', [$team, $app]),
                'builds_page' => route('accounts.apps.builds.index', [$team, $app]),
                'telemetry_page' => config('integrations.enabled') && config('integrations.sentry.enabled')
                    ? route('accounts.apps.telemetry.index', [$team, $app])
                    : null,
                'expo_page' => ExpoAppContext::available($team, $app)
                    ? route('accounts.apps.expo.index', [$team, $app])
                    : null,
                'update' => route('accounts.apps.update', [$team, $app]),
                'tags' => route('accounts.apps.tags.update', [$team, $app]),
                'destroy' => route('accounts.apps.destroy', [$team, $app]),
                'identifiers' => route('accounts.apps.identifiers.store', [$team, $app]),
                'credentials' => route('accounts.apps.credentials.store', [$team, $app]),
                'assets' => route('accounts.apps.assets.upload', [$team, $app, '__collection__']),
                'manual_builds' => route('accounts.apps.builds.store', [$team, $app]),
            ],
        ];
    }

    private static function pipelineConfigurationProp(Request $request, Team $team, MobileApp $app): ?array
    {
        if (! $team->allows($request->user(), TeamPermission::ManageIntegrations)) {
            return null;
        }

        $integrations = $team->integrations()
            ->where('status', IntegrationStatus::Active->value)
            ->with([
                'oauthCredential',
                'expoProjects' => fn ($query) => $query
                    ->where('mobile_app_id', $app->id)
                    ->where('exists_on_expo', true),
                'pipelines' => fn ($query) => $query
                    ->where('mobile_app_id', $app->id)
                    ->with('appIdentifier')
                    ->latest(),
            ])
            ->latest()
            ->get()
            ->filter(fn (Integration $integration) => $integration->supportsCiCd())
            ->filter(fn (Integration $integration) => $integration->provider !== IntegrationProvider::Expo
                || ($integration->oauthCredential !== null && $integration->expoProjects->isNotEmpty()))
            ->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'name' => $integration->name,
                'provider' => $integration->provider->value,
                'provider_name' => $integration->provider === IntegrationProvider::Expo
                    ? 'Expo EAS'
                    : $integration->provider->label(),
                'account_name' => $integration->external_account_name,
                'repositories_url' => route('accounts.integrations.repositories.index', [$team, $integration]),
                'workflows_url' => route('accounts.integrations.workflows.index', [$team, $integration]),
                'refs_url' => route('accounts.integrations.refs.index', [$team, $integration]),
                'pipelines_store_url' => route('accounts.integrations.pipelines.store', [$team, $integration]),
                'pipelines' => $integration->pipelines
                    ->map(fn (CiCdPipeline $pipeline) => self::configurablePipelineProp($team, $integration, $pipeline))
                    ->values(),
            ])
            ->values();

        return [
            'integrations_url' => route('accounts.integrations.index', $team),
            'profile_types' => array_map(fn (ProfileType $type) => $type->value, ProfileType::cases()),
            'reporting_modes' => array_map(fn (BuildReportingMode $mode) => $mode->value, BuildReportingMode::cases()),
            'integrations' => $integrations,
        ];
    }

    private static function configurablePipelineProp(
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
    ): array {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'enabled' => $pipeline->enabled,
            'mobile_app_id' => $pipeline->mobile_app_id,
            'app_identifier_id' => $pipeline->app_identifier_id,
            'target_name' => $pipeline->appIdentifier?->name ?: $pipeline->appIdentifier?->identifier,
            'profile_type' => $pipeline->profile_type->value,
            'reporting_mode' => $pipeline->reporting_mode->value,
            'build_id_input' => $pipeline->build_id_input,
            'repository_full_name' => $pipeline->repository_full_name,
            'workflow_name' => $pipeline->workflow_name,
            'workflow_id' => $pipeline->workflow_id,
            'default_ref' => $pipeline->default_ref,
            'input_schema' => $pipeline->input_schema ?? [],
            'input_defaults' => $pipeline->input_defaults ?? [],
            'run_url' => route('accounts.integrations.pipelines.runs.store', [$team, $integration, $pipeline]),
            'update_url' => route('accounts.integrations.pipelines.update', [$team, $integration, $pipeline]),
            'destroy_url' => route('accounts.integrations.pipelines.destroy', [$team, $integration, $pipeline]),
        ];
    }

    private static function pipelinesProp(Request $request, Team $team, MobileApp $app)
    {
        $canRunPipelines = $team->allows($request->user(), TeamPermission::RunPipelines);
        $expoProject = $app->expoProject()->first();

        return $canRunPipelines ? $app->ciCdPipelines()
            ->with('integration')
            ->where('enabled', true)
            ->latest()
            ->get()
            ->filter(fn ($pipeline) => $pipeline->integration->status->canDispatch()
                && ($pipeline->integration->provider !== IntegrationProvider::Expo
                    || ($expoProject?->integration_id === $pipeline->integration_id
                        && $expoProject?->external_id === $pipeline->repository_id
                        && $expoProject?->exists_on_expo)))
            ->map(fn ($pipeline) => PipelinePresenter::present($team, $pipeline))
            ->values() : collect();
    }

    /**
     * Aggregate the distinct tags used across the app: the app's own tags, tags on its
     * identifiers' credentials (services + profile credentials), and tags on its builds
     * and their artifacts. Shape matches TagEditor's `suggestions` prop: the canonical
     * compacted tuple, [text] / [text, color] / [text, color?, animation].
     */
    private static function tagSuggestions(MobileApp $app): array
    {
        $tagLists = collect([$app->tags ?? []]);

        $app->appIdentifiers()
            ->with(['credentials', 'profiles.credentials'])
            ->get()
            ->each(function ($identifier) use ($tagLists) {
                $identifier->credentials->each(fn ($credential) => $tagLists->push($credential->tags ?? []));
                $identifier->profiles->each(fn ($profile) => $profile->credentials->each(
                    fn ($credential) => $tagLists->push($credential->tags ?? [])
                ));
            });

        $app->buildRecords()
            ->with('artifacts')
            ->get()
            ->each(function ($build) use ($tagLists) {
                $tagLists->push($build->tags ?? []);
                $build->artifacts->each(fn ($artifact) => $tagLists->push($artifact->tags ?? []));
            });

        return $tagLists->flatten(1)
            ->filter(fn ($tag) => filled(is_array($tag) ? ($tag[0] ?? null) : $tag))
            // The cast already normalized these on read; re-shaping would only
            // truncate the animation, so legacy strings are the sole thing to fix up.
            ->map(fn ($tag) => is_array($tag) ? $tag : [$tag])
            ->unique(fn ($tag) => mb_strtolower($tag[0]))
            ->values()
            ->all();
    }
}
