<?php

namespace App\Jobs\Integrations;

use App\Actions\Integrations\ImportExpoProject as Importer;
use App\Enums\IntegrationProvider;
use App\Integrations\Expo\ExpoClient;
use App\Models\ExpoProject;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        if (! $project || $project->team_id !== $this->teamId) {
            return;
        }

        if ($project->mobile_app_id) {
            $project->update([
                'import_status' => 'completed',
                'import_error' => null,
                'import_finished_at' => now(),
            ]);

            return;
        }

        $project->update([
            'import_status' => 'importing',
            'import_error' => null,
            'import_started_at' => now(),
            'import_finished_at' => null,
        ]);

        if (! $project->exists_on_expo
            || $project->integration->provider !== IntegrationProvider::Expo
            || ! $project->integration->oauthCredential()->exists()) {
            throw new RuntimeException(
                'The Expo project or its account is no longer available. Sync or reconnect Expo and try again.',
            );
        }

        $team = Team::query()->findOrFail($this->teamId);
        $remote = $expo->projectWithCredentialSecrets(
            $project->integration,
            $project->external_id,
        );
        $importer->handle(
            $team,
            $project,
            $remote,
            useFirstIdentifierAsDefault: true,
        );

        $project->refresh()->update([
            'import_status' => 'completed',
            'import_error' => null,
            'import_finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->markFailed($exception);
        }
    }

    private function markFailed(Throwable $exception): void
    {
        ExpoProject::query()->whereKey($this->projectId)->update([
            'import_status' => 'failed',
            'import_error' => Str::limit($exception->getMessage(), 2000, ''),
            'import_finished_at' => now(),
        ]);
    }
}
