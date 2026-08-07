<?php

namespace App\Console\Commands;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\Blueprint;
use App\Models\BlueprintHook;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\Profile;
use App\Models\SentryDsn;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class PurgeDeletedData extends Command
{
    protected $signature = 'data-lifecycle:purge';

    protected $description = 'Permanently purge recoverable records after their trash retention window.';

    public function handle(): int
    {
        $days = (int) config('data-lifecycle.trash_retention_days', 90);
        $credentialDays = (int) config('data-lifecycle.credential_trash_retention_days', $days);

        if ($days < 1 || $credentialDays < 1) {
            $this->error('Trash retention periods must be at least 1 day.');

            return self::FAILURE;
        }

        $purged = 0;
        $failed = 0;

        // Roots run first so their model hooks can clean storage-backed descendants.
        // They cannot be force-deleted while a child build/Sentry archive is still
        // inside its own retention window.
        $this->purge(User::class, $days, $purged, $failed, fn (Builder $query) => $this->withoutRetainedTelemetry($query, 'ownedTeams.'));
        $this->purge(Team::class, $days, $purged, $failed, fn (Builder $query) => $this->withoutRetainedTelemetry($query));
        $this->purge(MobileApp::class, $days, $purged, $failed, fn (Builder $query) => $this->withoutRetainedTelemetry($query));

        $this->purge(Credential::class, $credentialDays, $purged, $failed);

        $this->purge(
            Integration::class,
            $days,
            $purged,
            $failed,
            fn (Builder $query) => $query->whereDoesntHave('sentryDsnIncludingTrashed.envelopes'),
        );

        foreach ([
            Profile::class,
            Artifact::class,
            Blueprint::class,
            BlueprintHook::class,
            CiCdPipeline::class,
            NotificationDestination::class,
            SentryIssue::class,
            WebhookEndpoint::class,
        ] as $modelClass) {
            $this->purge($modelClass, $days, $purged, $failed);
        }

        $this->purge(
            AppIdentifier::class,
            $days,
            $purged,
            $failed,
            fn (Builder $query) => $query->whereDoesntHave('buildRecords', fn (Builder $builds) => $this->retainedBuildLogs($builds)),
        );
        $this->purge(
            BuildRecord::class,
            $days,
            $purged,
            $failed,
            fn (Builder $query) => $this->withoutInlineOrArchivedBuildLog($query),
        );
        $this->purge(
            SentryDsn::class,
            $days,
            $purged,
            $failed,
            fn (Builder $query) => $query->whereDoesntHave('envelopes'),
        );

        $this->info("Purged {$purged} recoverable record(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param class-string<Model> $modelClass */
    private function purge(
        string $modelClass,
        int $days,
        int &$purged,
        int &$failed,
        ?callable $scope = null,
    ): void {
        $query = $modelClass::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days));

        if ($scope) {
            $scope($query);
        }

        $query
            ->lazyById(100)
            ->each(function (Model $model) use (&$purged, &$failed): void {
                try {
                    $model->forceDelete();
                    $purged++;
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error('Failed to purge '.get_class($model)." [{$model->getKey()}].");
                }
            });
    }

    private function withoutRetainedTelemetry(Builder $query, string $prefix = ''): Builder
    {
        return $query
            ->whereDoesntHave($prefix.'buildRecords', fn (Builder $builds) => $this->retainedBuildLogs($builds))
            ->whereDoesntHave($prefix.'sentryEnvelopes');
    }

    private function retainedBuildLogs(Builder $query): Builder
    {
        return $query->where(fn (Builder $logs) => $logs
            ->where(fn (Builder $inline) => $inline
                ->whereNotNull('log_output')
                ->where('log_output', '<>', ''))
            ->orWhereNotNull('log_archive_path'));
    }

    private function withoutInlineOrArchivedBuildLog(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $inline) => $inline
                ->whereNull('log_output')
                ->orWhere('log_output', ''))
            ->whereNull('log_archive_path');
    }
}
