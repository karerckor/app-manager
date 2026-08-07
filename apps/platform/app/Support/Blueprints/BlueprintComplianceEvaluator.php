<?php

namespace App\Support\Blueprints;

use App\Enums\BlueprintHookAction;
use App\Enums\BlueprintHookCondition;
use App\Models\BlueprintHook;
use App\Models\MobileApp;
use App\Support\Apps\AppFileLibrary;

class BlueprintComplianceEvaluator
{
    public function __construct(private readonly JsonSchemaMatcher $schemas) {}

    /**
     * @return array{
     *   matches: bool,
     *   metadata_matches: bool,
     *   expo_matches: bool,
     *   is_in_expo: bool,
     *   files: list<string>,
     *   platforms: list<string>,
     *   missing_files: list<string>,
     *   missing_platforms: list<string>
     * }
     */
    public function status(MobileApp $app): array
    {
        $app->loadMissing('blueprint');
        $blueprint = $app->blueprint;

        if ($blueprint === null) {
            return [
                'matches' => false,
                'metadata_matches' => true,
                'expo_matches' => true,
                'is_in_expo' => false,
                'files' => [],
                'platforms' => [],
                'missing_files' => [],
                'missing_platforms' => [],
            ];
        }

        $fileNames = $app->media()
            ->where('collection_name', AppFileLibrary::COLLECTION)
            ->pluck('file_name')->all();
        $platforms = $app->appIdentifiers()->get(['platform'])
            ->map(fn ($identifier) => $identifier->platform->value)
            ->unique()->values()->all();
        $missingFiles = array_values(array_diff($blueprint->required_files ?? [], $fileNames));
        $missingPlatforms = array_values(array_diff($blueprint->platforms ?? [], $platforms));
        $metadataMatches = $this->schemas->matches($app->metadata, $blueprint->json_schema);
        $isInExpo = $app->expoProject()->where('exists_on_expo', true)->exists();
        $expoMatches = $blueprint->requires_expo === null || $blueprint->requires_expo === $isInExpo;

        return [
            'matches' => $metadataMatches && $expoMatches && $missingFiles === [] && $missingPlatforms === [],
            'metadata_matches' => $metadataMatches,
            'expo_matches' => $expoMatches,
            'is_in_expo' => $isInExpo,
            'files' => array_values($fileNames),
            'platforms' => array_values($platforms),
            'missing_files' => $missingFiles,
            'missing_platforms' => $missingPlatforms,
        ];
    }

    public function evaluate(MobileApp $app): void
    {
        $app->loadMissing('blueprint.hooks');

        if ($app->blueprint === null) {
            return;
        }

        $status = $this->status($app);

        foreach ($app->blueprint->hooks->where('enabled', true) as $hook) {
            if ($this->conditionMatches($hook, $status)) {
                foreach ($hook->actions ?? [] as $action) {
                    $type = BlueprintHookAction::tryFrom($action['type'] ?? '');

                    if ($type === null) {
                        continue;
                    }

                    foreach ($action['tags'] ?? [] as $tag) {
                        $type === BlueprintHookAction::AddTag
                            ? $app->addTag($tag[0], $tag[1] ?? null, $tag[2] ?? null)
                            : $app->removeTag($tag[0]);
                    }
                }
            }
        }

        if ($app->isDirty('tags')) {
            $app->save();
        }
    }

    /** @param array{matches: bool, metadata_matches: bool, expo_matches: bool, is_in_expo: bool, files: list<string>, platforms: list<string>, missing_files: list<string>, missing_platforms: list<string>} $status */
    private function conditionMatches(BlueprintHook $hook, array $status): bool
    {
        $config = $hook->condition_config ?? [];

        return match ($hook->condition) {
            BlueprintHookCondition::MetadataIncomplete => ! $status['metadata_matches'],
            BlueprintHookCondition::MetadataMatches => $status['metadata_matches'],
            BlueprintHookCondition::FileMissing => ! in_array($config['file_name'] ?? null, $status['files'], true),
            BlueprintHookCondition::FilePresent => in_array($config['file_name'] ?? null, $status['files'], true),
            BlueprintHookCondition::PlatformMissing => ! in_array($config['platform'] ?? null, $status['platforms'], true),
            BlueprintHookCondition::PlatformPresent => in_array($config['platform'] ?? null, $status['platforms'], true),
            BlueprintHookCondition::BlueprintMismatch => ! $status['matches'],
            BlueprintHookCondition::BlueprintMatches => $status['matches'],
        };
    }
}
