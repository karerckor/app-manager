<?php

namespace App\Http\Controllers;

use App\Http\Concerns\NormalizesFileMetadata;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppFileLibrary;
use App\Support\Apps\AppFilePreview;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFileController extends Controller
{
    use NormalizesFileMetadata;

    public function __construct(private readonly AppFileLibrary $files) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team, $mobileApp);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('media-library.max_file_size_kb')],
            'metadata' => ['nullable', 'string', 'max:65535', 'json'],
        ]);

        $this->files->attach($mobileApp, $data['file'], $this->fileMetadata($data['metadata'] ?? null));

        return back()->with('status', 'File uploaded.');
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): RedirectResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);

        $data = $request->validate([
            'metadata' => ['nullable', 'string', 'max:65535', 'json'],
        ]);

        $this->files->updateMetadata($media, $this->fileMetadata($data['metadata'] ?? null));

        return back()->with('status', 'File metadata updated.');
    }

    public function download(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $fileName,
    ): StreamedResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $media = $this->files->findByName($mobileApp, $fileName);
        abort_unless($media instanceof Media, 404);

        return MediaFileResponse::download($media, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): StreamedResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);
        abort_unless(AppFilePreview::type($media->mime_type), 404);

        return MediaFileResponse::inline($media, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): RedirectResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);
        $this->files->detach($media);

        return back()->with('status', 'File deleted.');
    }

    private function authorizeTeam(Request $request, Team $team, MobileApp $mobileApp): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function assertFileBelongsToApp(MobileApp $mobileApp, Media $media): void
    {
        abort_unless($this->files->belongsToApp($mobileApp, $media), 404);
    }
}
