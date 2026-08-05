<?php

namespace App\Support\Builds;

use App\Enums\BuildStatus;
use App\Models\BuildRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildLifecycle
{
    public function start(BuildRecord $record, array $ci = []): BuildRecord
    {
        return DB::transaction(function () use ($record, $ci) {
            $locked = BuildRecord::query()->lockForUpdate()->findOrFail($record->id);

            if ($locked->status === BuildStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->value} build cannot be started.",
                ]);
            }

            if ($locked->status === BuildStatus::Running) {
                if (
                    $locked->ci_provider !== $ci['ci_provider']
                    || $locked->ci_run_id !== $ci['ci_run_id']
                ) {
                    throw ValidationException::withMessages([
                        'ci_run_id' => 'This build has already been claimed by another CI run.',
                    ]);
                }

                return $locked;
            }

            $ciRunAlreadyClaimed = BuildRecord::query()
                ->where('team_id', $locked->team_id)
                ->where('ci_provider', $ci['ci_provider'])
                ->where('ci_run_id', $ci['ci_run_id'])
                ->whereKeyNot($locked->id)
                ->exists();

            if ($ciRunAlreadyClaimed) {
                throw ValidationException::withMessages([
                    'ci_run_id' => 'This CI run is already attached to another build.',
                ]);
            }

            $locked->update([
                'status' => BuildStatus::Running,
                'ci_provider' => $ci['ci_provider'],
                'ci_run_id' => $ci['ci_run_id'],
                'ci_run_url' => $ci['ci_run_url'] ?? $locked->ci_run_url,
                'started_at' => $locked->started_at ?? now(),
            ]);

            return $locked->refresh();
        });
    }

    public function finish(
        BuildRecord $record,
        BuildStatus $status,
        ?string $artifactUrl = null,
    ): BuildRecord {
        return DB::transaction(function () use ($record, $status, $artifactUrl) {
            $locked = BuildRecord::query()->lockForUpdate()->findOrFail($record->id);

            if ($locked->status->isTerminal()) {
                if ($locked->status === $status) {
                    return $locked;
                }

                throw ValidationException::withMessages([
                    'status' => 'A terminal build record cannot transition to another status.',
                ]);
            }

            if ($status !== BuildStatus::Cancelled && $locked->status !== BuildStatus::Running) {
                throw ValidationException::withMessages([
                    'status' => 'A build must be running before it can be completed or failed.',
                ]);
            }

            $locked->update([
                'status' => $status,
                'artifact_url' => $artifactUrl ?? $locked->artifact_url,
                'finished_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
