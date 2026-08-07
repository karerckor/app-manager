<?php

namespace App\Support\Builds;

use App\Models\BuildRecord;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BuildLogDownload
{
    public function download(BuildRecord $build): Response
    {
        $contents = $this->contents($build);
        abort_if($contents === null, 404);

        $plainText = AnsiText::strip($contents);

        return response($plainText, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="build-%s.log"', $build->id),
            'Content-Length' => (string) strlen($plainText),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function contents(BuildRecord $build): ?string
    {
        if ($build->log_output !== null && $build->log_output !== '') {
            return $build->log_output;
        }

        if (! $build->log_archive_disk || ! $build->log_archive_path) {
            return null;
        }

        $storage = Storage::disk($build->log_archive_disk);
        abort_unless($storage->exists($build->log_archive_path), 404);

        return $storage->get($build->log_archive_path);
    }
}
