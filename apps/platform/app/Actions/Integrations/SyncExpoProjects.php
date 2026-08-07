<?php

namespace App\Actions\Integrations;

use App\Integrations\Expo\ExpoClient;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Support\Facades\DB;

class SyncExpoProjects
{
    public function __construct(
        private readonly ExpoClient $expo,
        private readonly BlueprintComplianceEvaluator $blueprints,
    ) {}

    /** @return array<int, ExpoProject> */
    public function handle(Integration $integration): array
    {
        $projects = $this->expo->projects($integration);
        $seen = collect($projects)->pluck('id')->filter()->map(fn ($id) => (string) $id);
        $affectedAppIds = $integration->expoProjects()
            ->whereNotNull('mobile_app_id')
            ->pluck('mobile_app_id');

        $synced = DB::transaction(function () use ($integration, $projects, $seen): array {
            $integration->expoProjects()
                ->when($seen->isNotEmpty(), fn ($query) => $query->whereNotIn('external_id', $seen))
                ->when($seen->isEmpty(), fn ($query) => $query)
                ->update(['exists_on_expo' => false]);

            $synced = collect($projects)->map(function (array $project) use ($integration) {
                $expoProject = ExpoProject::query()->updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'external_id' => (string) $project['id'],
                    ],
                    $this->attributes($integration, $project),
                );

                if ($expoProject->mobileApp && $expoProject->sync_metadata) {
                    $expoProject->mobileApp->update([
                        'name' => $expoProject->name,
                        'description' => $expoProject->description,
                    ]);
                }

                return $expoProject;
            })->all();

            $integration->update(['last_synced_at' => now()]);

            return $synced;
        });

        MobileApp::query()->whereKey($affectedAppIds)->each(
            fn (MobileApp $app) => $this->blueprints->evaluate($app),
        );

        return $synced;
    }

    /** @return array<string, mixed> */
    public function attributes(Integration $integration, array $project): array
    {
        return [
            'team_id' => $integration->team_id,
            'account_id' => (string) data_get($project, 'ownerAccount.id', $integration->external_account_id),
            'account_name' => (string) data_get($project, 'ownerAccount.name', $integration->external_account_name),
            'slug' => (string) ($project['slug'] ?? ''),
            'name' => (string) ($project['name'] ?? $project['slug'] ?? ''),
            'full_name' => (string) ($project['fullName'] ?? ''),
            'description' => filled($project['description'] ?? null)
                ? (string) $project['description']
                : null,
            'icon_url' => filled($project['profileImageUrl'] ?? null)
                ? (string) $project['profileImageUrl']
                : null,
            'exists_on_expo' => true,
            'last_synced_at' => now(),
        ];
    }
}
