<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppMediaLibrary;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppAssetController extends Controller
{
    private const COLLECTIONS = [
        'icon' => 'app_icon',
        'splashscreen' => 'splash_icon',
    ];

    public function __construct(private readonly ExpoAutoSyncDispatcher $expoAutoSync) {}

    public function files(Team $team, MobileApp $mobileApp): JsonResponse
    {
        $files = collect(self::COLLECTIONS)
            ->flatMap(function (string $collection, string $asset) use ($team, $mobileApp) {
                $media = $mobileApp->getFirstMedia($collection);

                if (! $media) {
                    return [];
                }

                $urls = [
                    "{$asset}.original" => route('api.v1.apps.assets.files.show', [
                        $team, $mobileApp, $asset, 'original',
                    ]),
                ];

                foreach (AppMediaLibrary::variants($collection) as $variant) {
                    if (! $media->hasGeneratedConversion($variant['name'])) {
                        continue;
                    }

                    $urls["{$asset}.{$variant['name']}"] = route('api.v1.apps.assets.files.show', [
                        $team, $mobileApp, $asset, $variant['name'],
                    ]);
                }

                return $urls;
            })
            ->all();

        return response()->json(['data' => $files]);
    }

    public function download(
        Team $team,
        MobileApp $mobileApp,
        string $asset,
        string $file,
    ): StreamedResponse {
        $collection = self::COLLECTIONS[$asset];
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        if ($file === 'original') {
            return MediaFileResponse::download($media);
        }

        abort_unless(AppMediaLibrary::variant($collection, $file), 404);
        abort_unless($media->hasGeneratedConversion($file), 404);

        return MediaFileResponse::downloadConversion($media, $file, "{$file}.png");
    }

    public function show(Team $team, MobileApp $mobileApp, string $asset): StreamedResponse
    {
        $media = $mobileApp->getFirstMedia(self::COLLECTIONS[$asset]);
        abort_unless($media, 404);

        return MediaFileResponse::inline($media, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp, string $asset): MobileAppResource
    {
        $this->authorize('manageApplications', $team);

        $request->validate([
            'file' => ['required', 'image', 'max:10240'],
        ]);

        $mobileApp->addMediaFromRequest('file')->toMediaCollection(self::COLLECTIONS[$asset]);
        if ($asset === 'icon') {
            $this->expoAutoSync->dispatchForAppIds([$mobileApp->id]);
        }

        return new MobileAppResource($mobileApp->refresh());
    }

    public function destroy(Team $team, MobileApp $mobileApp, string $asset): Response
    {
        $this->authorize('manageApplications', $team);

        abort_unless($mobileApp->getFirstMedia(self::COLLECTIONS[$asset]), 404);
        $mobileApp->clearMediaCollection(self::COLLECTIONS[$asset]);
        if ($asset === 'icon') {
            $this->expoAutoSync->dispatchForAppIds([$mobileApp->id]);
        }

        return response()->noContent();
    }
}
