<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppFilePreview;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFileController extends Controller
{
    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team, $mobileApp);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('media-library.max_file_size_kb')],
            'metadata' => ['nullable', 'string', 'max:65535', 'json'],
        ]);
        $metadata = $this->metadata($data['metadata'] ?? null);
        $fileAdder = $mobileApp->addMediaFromRequest('file');

        if ($metadata !== null) {
            $fileAdder->withCustomProperties(['metadata' => $metadata]);
        }

        $media = $fileAdder->toMediaCollection('files');
        $duplicate = $mobileApp->getMedia('files')->first(
            fn (Media $candidate) => $candidate->getKey() !== $media->getKey()
                && hash_equals($candidate->file_name, $media->file_name),
        );

        if ($duplicate) {
            $media->delete();

            throw ValidationException::withMessages([
                'file' => "A file named {$media->file_name} is already attached.",
            ]);
        }

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
        $metadata = $this->metadata($data['metadata'] ?? null);

        if ($metadata === null) {
            $media->forgetCustomProperty('metadata');
        } else {
            $media->setCustomProperty('metadata', $metadata);
        }

        $media->save();

        return back()->with('status', 'File metadata updated.');
    }

    public function download(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $fileName,
    ): StreamedResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $media = $this->fileByName($mobileApp, $fileName);

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
        $media->delete();

        return back()->with('status', 'File deleted.');
    }

    private function authorizeTeam(Request $request, Team $team, MobileApp $mobileApp): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function assertFileBelongsToApp(MobileApp $mobileApp, Media $media): void
    {
        abort_unless(
            $media->model_type === $mobileApp->getMorphClass()
                && (string) $media->model_id === (string) $mobileApp->getKey()
                && $media->collection_name === 'files',
            404,
        );
    }

    private function fileByName(MobileApp $mobileApp, string $fileName): Media
    {
        $media = $mobileApp->getMedia('files')->first(
            fn (Media $candidate) => hash_equals($candidate->file_name, $fileName),
        );

        abort_unless($media instanceof Media, 404);

        return $media;
    }

    /** @return array<string, mixed>|null */
    private function metadata(?string $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        $decodedObject = json_decode($value);

        if (! is_object($decodedObject)) {
            throw ValidationException::withMessages([
                'metadata' => 'Metadata must be a JSON object.',
            ]);
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
