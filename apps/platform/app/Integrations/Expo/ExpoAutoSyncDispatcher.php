<?php

namespace App\Integrations\Expo;

use App\Jobs\Integrations\PushExpoProject;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpoAutoSyncDispatcher
{
    public function dispatchForAggregate(Model|string $aggregate): void
    {
        if ($aggregate instanceof MobileApp) {
            $this->dispatchForAppIds([$aggregate->id]);

            return;
        }

        if ($aggregate instanceof AppIdentifier) {
            $this->dispatchForAppIds([$aggregate->mobile_app_id]);

            return;
        }

        if ($aggregate instanceof Credential) {
            $this->dispatchForAppIds($this->appIdsForCredential($aggregate));
        }
    }

    /** @return Collection<int, string> */
    public function appIdsForCredential(Credential $credential): Collection
    {
        $direct = $credential->appIdentifiers()->pluck('mobile_app_id');
        $profiles = $credential->profiles()
            ->with('appIdentifier:id,mobile_app_id')
            ->get()
            ->pluck('appIdentifier.mobile_app_id');

        return $direct
            ->concat($profiles)
            ->filter()
            ->unique()
            ->values();
    }

    /** @param iterable<int, string|null> $appIds */
    public function dispatchForAppIds(iterable $appIds): void
    {
        if (! config('integrations.enabled') || ! config('integrations.expo.enabled')) {
            return;
        }

        $ids = collect($appIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $projectIds = ExpoProject::query()
            ->whereIn('mobile_app_id', $ids)
            ->where('auto_sync_enabled', true)
            ->where('exists_on_expo', true)
            ->whereHas('integration', fn ($query) => $query->activeExpo())
            ->pluck('id');

        foreach ($projectIds as $projectId) {
            DB::afterCommit(function () use ($projectId): void {
                $queued = ExpoProject::query()
                    ->whereKey($projectId)
                    ->where('auto_sync_enabled', true)
                    ->whereNotNull('mobile_app_id')
                    ->update([
                        'auto_sync_status' => 'queued',
                        'auto_sync_error' => null,
                        'auto_sync_pending_at' => now(),
                    ]);

                if ($queued) {
                    PushExpoProject::dispatch($projectId);
                }
            });
        }
    }
}
