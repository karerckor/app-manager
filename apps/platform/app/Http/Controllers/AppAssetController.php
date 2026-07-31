<?php

namespace App\Http\Controllers;

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
    public function upload(Request $request, Team $team, MobileApp $mobileApp, string $collection): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        validator(['collection' => $collection], [
            'collection' => ['required', Rule::in(['app_icon', 'splash_icon'])],
        ])->validate();
        $request->validate(['file' => ['required', 'image', 'max:10240']]);
        $mobileApp->addMediaFromRequest('file')->toMediaCollection($collection);

        return back()->with('status', 'App asset updated.');
    }

    public function download(Request $request, Team $team, MobileApp $mobileApp, string $collection): StreamedResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless(in_array($collection, ['app_icon', 'splash_icon'], true), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        return MediaFileResponse::download($media);
    }

    public function preview(Request $request, Team $team, MobileApp $mobileApp, string $collection): StreamedResponse
    {
        $this->authorizeTeam($request, $team);
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
        $this->authorizeTeam($request, $team);
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
        $this->authorizeTeam($request, $team);
        abort_unless(AppMediaLibrary::variant($collection, $conversion), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media?->hasGeneratedConversion($conversion), 404);

        return MediaFileResponse::inlineConversion($media, $conversion, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
