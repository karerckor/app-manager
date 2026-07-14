<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppAssetController extends Controller
{
    private const COLLECTIONS = [
        'icon' => 'app_icon',
        'splashscreen' => 'splash_icon',
    ];

    public function show(Team $team, MobileApp $mobileApp, string $asset): BinaryFileResponse
    {
        $media = $mobileApp->getFirstMedia(self::COLLECTIONS[$asset]);
        abort_unless($media, 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp, string $asset): MobileAppResource
    {
        $request->validate([
            'file' => ['required', 'image', 'max:10240'],
        ]);

        $mobileApp->addMediaFromRequest('file')->toMediaCollection(self::COLLECTIONS[$asset]);

        return new MobileAppResource($mobileApp->refresh());
    }

    public function destroy(Team $team, MobileApp $mobileApp, string $asset): Response
    {
        abort_unless($mobileApp->getFirstMedia(self::COLLECTIONS[$asset]), 404);
        $mobileApp->clearMediaCollection(self::COLLECTIONS[$asset]);

        return response()->noContent();
    }
}
