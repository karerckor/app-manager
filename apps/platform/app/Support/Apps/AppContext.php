<?php

namespace App\Support\Apps;

use App\Enums\IntegrationProvider;
use App\Enums\TeamPermission;
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
            'urls' => [
                'index' => route('accounts.apps.index', [$team]),
                'overview' => route('accounts.apps.show', [$team, $app]),
                'media_page' => route('accounts.apps.media.index', [$team, $app]),
                'media_regenerate' => route('accounts.apps.media.regenerate', [$team, $app]),
                'identifiers_page' => route('accounts.apps.identifiers.index', [$team, $app]),
                'builds_page' => route('accounts.apps.builds.index', [$team, $app]),
                'expo_page' => ExpoAppContext::available($team)
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
     * and their artifacts. Shape matches TagEditor's `suggestions` prop: [tag, color|null][].
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
            ->filter(fn ($tag) => filled($tag[0] ?? null))
            ->map(fn ($tag) => [$tag[0], $tag[1] ?? null])
            ->unique(fn ($tag) => mb_strtolower($tag[0]))
            ->values()
            ->all();
    }
}
