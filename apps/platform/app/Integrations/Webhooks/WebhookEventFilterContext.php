<?php

namespace App\Integrations\Webhooks;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class WebhookEventFilterContext
{
    /**
     * @return array{
     *     mobile_app_ids: list<string>,
     *     tags: list<string>,
     *     build_status: string|null,
     *     profile_type: string|null
     * }
     */
    public function for(Model|string $aggregate): array
    {
        $mobileApps = collect();
        $tags = $aggregate instanceof Model ? $this->tags($aggregate) : [];
        $build = null;

        if ($aggregate instanceof MobileApp) {
            $mobileApps->push($aggregate);
        } elseif ($aggregate instanceof BuildRecord) {
            $build = $aggregate;
            $aggregate->loadMissing('mobileApp');
            $mobileApps->push($aggregate->mobileApp);
        } elseif ($aggregate instanceof Artifact) {
            $aggregate->loadMissing('buildRecord.mobileApp');
            $build = $aggregate->buildRecord;
            $mobileApps->push($build?->mobileApp);
            $tags = [...$tags, ...$this->tags($build)];
        } elseif ($aggregate instanceof Media) {
            $aggregate->loadMissing('model');

            if ($aggregate->model instanceof MobileApp) {
                $mobileApps->push($aggregate->model);
            }
        } elseif ($aggregate instanceof AppIdentifier) {
            $aggregate->loadMissing('mobileApp');
            $mobileApps->push($aggregate->mobileApp);
        } elseif ($aggregate instanceof Credential && $aggregate->exists) {
            $aggregate->loadMissing([
                'appIdentifiers.mobileApp',
                'profiles.appIdentifier.mobileApp',
            ]);
            $mobileApps = $aggregate->appIdentifiers
                ->pluck('mobileApp')
                ->merge($aggregate->profiles->pluck('appIdentifier.mobileApp'));
        }

        $mobileApps = $mobileApps
            ->filter()
            ->unique('id')
            ->values();

        foreach ($mobileApps as $mobileApp) {
            $tags = [...$tags, ...$this->tags($mobileApp)];
        }

        return [
            'mobile_app_ids' => $mobileApps->pluck('id')->all(),
            'tags' => $this->normalizeTags($tags),
            'build_status' => $build?->status?->value,
            'profile_type' => $build?->profile_type?->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function tags(?Model $model): array
    {
        if (! $model || ! method_exists($model, 'tagList')) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $tag) => match (true) {
                is_string($tag) => $tag,
                is_array($tag) && is_string($tag[0] ?? null) => $tag[0],
                default => null,
            },
            $model->tagList(),
        )));
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn (string $tag) => mb_strtolower(trim($tag)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
