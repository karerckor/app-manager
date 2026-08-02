<?php

namespace App\Support\Builds;

use App\Models\BuildRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildLog
{
    public const MAX_CHUNK_BYTES = 262_144;

    public const MAX_TOTAL_BYTES = 5_000_000;

    public function __construct(private readonly BuildActorResolver $actors) {}

    public function append(BuildRecord $build, string $content): BuildRecord
    {
        return $this->actors->runAs($build, function () use ($build, $content) {
            return DB::transaction(function () use ($build, $content) {
                $current = BuildRecord::query()->lockForUpdate()->findOrFail($build->id);

                if ($current->logs_archived_at !== null) {
                    throw ValidationException::withMessages([
                        'content' => 'This build log has already been archived.',
                    ]);
                }

                if (strlen($content) > self::MAX_CHUNK_BYTES) {
                    throw ValidationException::withMessages([
                        'content' => 'A build log chunk may not exceed 256 KiB.',
                    ]);
                }

                $output = ($current->log_output ?? '').$content;

                if (strlen($output) > self::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages([
                        'content' => 'The build log may not exceed 5 MB.',
                    ]);
                }

                $current->update([
                    'log_output' => $output,
                    'logs_updated_at' => now(),
                ]);

                return $current->refresh();
            });
        });
    }

    public function replace(BuildRecord $build, string $content): BuildRecord
    {
        if ($build->logs_archived_at !== null) {
            throw ValidationException::withMessages([
                'content' => 'This build log has already been archived.',
            ]);
        }

        if (strlen($content) > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'content' => 'The build log may not exceed 5 MB.',
            ]);
        }

        return $this->actors->runAs($build, function () use ($build, $content) {
            $build->update([
                'log_output' => $content,
                'logs_updated_at' => now(),
            ]);

            return $build->refresh();
        });
    }
}
