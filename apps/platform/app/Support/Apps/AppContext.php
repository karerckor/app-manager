<?php

namespace App\Support\Apps;

use App\Enums\TeamPermission;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use Illuminate\Http\Request;

class AppContext
{
    public static function for(Request $request, Team $team, MobileApp $app): array
    {
        $canRunPipelines = $team->allows($request->user(), TeamPermission::RunPipelines);

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
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'tags' => $app->tags ?? [],
                'created_at' => $app->created_at,
                'updated_at' => $app->updated_at,
                'assets' => $assets,
                'urls' => [
                    'index' => route('accounts.apps.index', [$team]),
                    'overview' => route('accounts.apps.show', [$team, $app]),
                    'identifiers_page' => route('accounts.apps.identifiers.index', [$team, $app]),
                    'builds_page' => route('accounts.apps.builds.index', [$team, $app]),
                    'update' => route('accounts.apps.update', [$team, $app]),
                    'tags' => route('accounts.apps.tags.update', [$team, $app]),
                    'destroy' => route('accounts.apps.destroy', [$team, $app]),
                    'identifiers' => route('accounts.apps.identifiers.store', [$team, $app]),
                    'credentials' => route('accounts.apps.credentials.store', [$team, $app]),
                    'assets' => route('accounts.apps.assets.upload', [$team, $app, '__collection__']),
                    'manual_builds' => route('accounts.apps.builds.store', [$team, $app]),
                ],
            ],
            'pipelines' => $canRunPipelines ? $app->ciCdPipelines()
                ->with('integration')
                ->where('enabled', true)
                ->latest()
                ->get()
                ->filter(fn ($pipeline) => $pipeline->integration->status->canDispatch())
                ->map(fn ($pipeline) => PipelinePresenter::present($team, $pipeline))
                ->values() : collect(),
            'identifiers' => $app->appIdentifiers()
                ->orderBy('platform')->orderBy('name')->get()
                ->map(fn ($identifier) => [
                    'id' => $identifier->id,
                    'platform' => $identifier->platform->value,
                    'identifier' => $identifier->identifier,
                    'name' => $identifier->name,
                ])->values(),
            'buildProfiles' => BuildProfileSchema::definitions(),
        ];
    }
}
