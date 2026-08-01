<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\NormalizesFileMetadata;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppFileResource;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppFileLibrary;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFileController extends Controller
{
    use NormalizesFileMetadata, ResolvesPageSize;

    public function __construct(private readonly AppFileLibrary $files) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $this->assertAppBelongs($team, $mobileApp);

        return AppFileResource::collection(
            $this->files->query($mobileApp)->latest('id')->paginate($this->perPage($request)),
        );
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->assertAppBelongs($team, $mobileApp);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('media-library.max_file_size_kb')],
            'metadata' => ['nullable'],
        ]);

        $media = $this->files->attach(
            $mobileApp,
            $data['file'],
            $this->fileMetadata($request->input('metadata')),
        );

        return (new AppFileResource($media))->response()->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp, string $file): AppFileResource
    {
        return new AppFileResource($this->file($team, $mobileApp, $file));
    }

    public function content(Team $team, MobileApp $mobileApp, string $file): StreamedResponse
    {
        return MediaFileResponse::download($this->file($team, $mobileApp, $file), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Team $team, MobileApp $mobileApp, string $file): AppFileResource
    {
        $media = $this->file($team, $mobileApp, $file);
        $request->validate(['metadata' => ['nullable']]);

        return new AppFileResource($this->files->updateMetadata(
            $media,
            $this->fileMetadata($request->input('metadata')),
        ));
    }

    public function destroy(Team $team, MobileApp $mobileApp, string $file): Response
    {
        $this->files->detach($this->file($team, $mobileApp, $file));

        return response()->noContent();
    }

    private function assertAppBelongs(Team $team, MobileApp $mobileApp): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function file(Team $team, MobileApp $mobileApp, string $file): Media
    {
        $this->assertAppBelongs($team, $mobileApp);
        $media = $this->files->findByName($mobileApp, $file);
        abort_unless($media instanceof Media, 404);

        return $media;
    }
}
