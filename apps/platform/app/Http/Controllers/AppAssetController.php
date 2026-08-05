<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppAssetController extends Controller
{
    public function upload(Request $request, Team $team, MobileApp $mobileApp, string $collection): RedirectResponse
    {
        $this->authorize('manageApplications', $team);
        validator(['collection' => $collection], [
            'collection' => ['required', Rule::in(['app_icon', 'splash_icon'])],
        ])->validate();
        $request->validate(['file' => ['required', 'image', 'max:10240']]);
        $mobileApp->addMediaFromRequest('file')->toMediaCollection($collection);

        return back()->with('status', 'App asset updated.');
    }

    public function download(Request $request, Team $team, MobileApp $mobileApp, string $collection): BinaryFileResponse
    {
        $this->authorize('view', $team);
        abort_unless(in_array($collection, ['app_icon', 'splash_icon'], true), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    public function preview(Request $request, Team $team, MobileApp $mobileApp, string $collection): BinaryFileResponse
    {
        $this->authorize('view', $team);
        abort_unless(in_array($collection, ['app_icon', 'splash_icon'], true), 404);
        $media = $mobileApp->getFirstMedia($collection);
        abort_unless($media, 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
