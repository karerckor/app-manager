<?php

namespace App\Http\Controllers;

use App\Integrations\Expo\ExpoAutoSyncDispatcher;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppMediaLibrary;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppAssetController extends Controller
{
    public function __construct(private readonly ExpoAutoSyncDispatcher $expoAutoSync) {}

    public function upload(Request $request, Team $team, MobileApp $mobileApp, string $collection): RedirectResponse
    {
        validator(['collection' => $collection], [
            'collection' => ['required', Rule::in(['app_icon', 'splash_icon'])],
        ])->validate();
        $request->validate(['file' => ['required', 'image', 'max:10240']]);
        $mobileApp->addMediaFromRequest('file')->toMediaCollection($collection);
        if ($collection === 'app_icon') {
            $this->expoAutoSync->dispatchForAppIds([$mobileApp->id]);
        }

        return back()->with('status', 'App asset updated.');
    }

    public function download(Request $request, Team $team, MobileApp $mobileApp, string $collection): StreamedResponse
    {
        abort_unless(in_array($collection, ['app_icon', 'splash_icon'], true), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        return MediaFileResponse::download($media);
    }

    public function preview(Request $request, Team $team, MobileApp $mobileApp, string $collection): StreamedResponse
    {
        abort_unless(in_array($collection, ['app_icon', 'splash_icon'], true), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        return MediaFileResponse::inline($media, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadConversion(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $collection,
        string $conversion,
    ): StreamedResponse {
        $variant = AppMediaLibrary::variant($collection, $conversion);
        abort_unless($variant, 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media?->hasGeneratedConversion($conversion), 404);

        return MediaFileResponse::downloadConversion(
            $media,
            $conversion,
            "{$conversion}.png",
        );
    }

    public function previewConversion(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $collection,
        string $conversion,
    ): StreamedResponse {
        abort_unless(AppMediaLibrary::variant($collection, $conversion), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media?->hasGeneratedConversion($conversion), 404);

        return MediaFileResponse::inlineConversion($media, $conversion, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
