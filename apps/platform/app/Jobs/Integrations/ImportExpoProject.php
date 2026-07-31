<?php

namespace App\Jobs\Integrations;

use App\Actions\Integrations\ImportExpoProject as Importer;
use App\Enums\IntegrationProvider;
use App\Integrations\Expo\ExpoClient;
use App\Models\ExpoProject;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportExpoProject implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(
        public readonly string $teamId,
        public readonly string $projectId,
    ) {}

    public function handle(ExpoClient $expo, Importer $importer): void
    {
        $project = ExpoProject::query()->with('integration')->find($this->projectId);

        if (! $project
            || $project->team_id !== $this->teamId
            || ! $project->exists_on_expo
            || $project->mobile_app_id
            || $project->integration->provider !== IntegrationProvider::Expo
            || ! $project->integration->oauthCredential()->exists()) {
            return;
        }

        $team = Team::query()->findOrFail($this->teamId);
        $remote = $expo->projectWithCredentialSecrets($project->integration, $project->external_id);
        $importer->handle($team, $project, $remote);
    }
}
