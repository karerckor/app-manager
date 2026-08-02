<?php

namespace App\Support\Builds;

use App\Models\BuildRecord;
use App\Models\CiCdRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildLogArchive
{
    public function archive(BuildRecord $build): bool
    {
        return DB::transaction(function () use ($build): bool {
            $locked = BuildRecord::query()->lockForUpdate()->findOrFail($build->id);

            if ($locked->log_output === null || $locked->log_output === '') {
                return false;
            }

            $disk = (string) config('media-library.disk_name', 'media-local');
            $path = $this->path($locked);
            $contents = $locked->log_output;

            if (! Storage::disk($disk)->put($path, $contents, [
                'visibility' => 'private',
                'ContentType' => 'text/plain; charset=utf-8',
            ])) {
                throw new RuntimeException("Could not archive logs for build [{$locked->id}].");
            }

            BuildRecord::query()->whereKey($locked->id)->update([
                'log_output' => null,
                'log_archive_disk' => $disk,
                'log_archive_path' => $path,
                'log_archive_size' => strlen($contents),
                'logs_archived_at' => now(),
            ]);

            CiCdRun::query()->where('build_record_id', $locked->id)->update([
                'log_output' => null,
            ]);

            return true;
        });
    }

    public function download(BuildRecord $build): StreamedResponse
    {
        abort_unless($build->log_archive_disk && $build->log_archive_path, 404);

        $storage = Storage::disk($build->log_archive_disk);
        abort_unless($storage->exists($build->log_archive_path), 404);

        return $storage->download(
            $build->log_archive_path,
            "build-{$build->id}.log",
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Length' => (string) $build->log_archive_size,
            ],
        );
    }

    private function path(BuildRecord $build): string
    {
        return "build-log-archives/{$build->team_id}/{$build->mobile_app_id}/{$build->id}.log";
    }
}
