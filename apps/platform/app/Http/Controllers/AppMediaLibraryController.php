<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\AppFileLibrary;
use App\Support\Apps\AppFilePreview;
use App\Support\Apps\AppMediaLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppMediaLibraryController extends Controller
{
    public function __construct(private readonly AppFileLibrary $files) {}

    public function __invoke(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        return Inertia::render('Apps/Media/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'media',
            'fileLibrary' => [
                'upload_url' => route('accounts.apps.media.files.store', [$team, $mobileApp]),
                'max_file_size' => config('media-library.max_file_size_kb') * 1024,
                'files' => $this->files->query($mobileApp)
                    ->latest()
                    ->get()
                    ->map(function (Media $media) use ($team, $mobileApp) {
                        $previewType = AppFilePreview::type($media->mime_type);

                        return [
                            'id' => $media->id,
                            'name' => $media->file_name,
                            'extension' => strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)),
                            'mime_type' => $media->mime_type ?: 'application/octet-stream',
                            'size' => $media->size,
                            'metadata' => $media->getCustomProperty('metadata'),
                            'created_at' => $media->created_at?->toIso8601String(),
                            'preview_type' => $previewType,
                            'urls' => [
                                'preview' => $previewType ? route('accounts.apps.media.files.preview', [
                                    $team, $mobileApp, $media,
                                ]) : null,
                                'download' => route('accounts.apps.media.files.download', [
                                    'team' => $team,
                                    'mobileApp' => $mobileApp,
                                    'fileName' => $media->file_name,
                                ]),
                                'update' => route('accounts.apps.media.files.update', [
                                    $team, $mobileApp, $media,
                                ]),
                                'destroy' => route('accounts.apps.media.files.destroy', [
                                    $team, $mobileApp, $media,
                                ]),
                            ],
                        ];
                    })->values(),
            ],
            'mediaLibrary' => collect(AppMediaLibrary::assets())
                ->map(fn (array $asset, string $collection) => [
                    'collection' => $collection,
                    'label' => $asset['label'],
                    'description' => $asset['description'],
                    'recommendation' => $asset['recommendation'],
                    'source' => $this->source($team, $mobileApp, $collection),
                    'platforms' => collect($asset['platforms'])->map(fn (array $platform) => [
                        'label' => $platform['label'],
                        'variants' => collect($platform['variants'])->map(
                            fn (array $variant) => $this->variant($team, $mobileApp, $collection, $variant)
                        )->values(),
                    ])->values(),
                ])->values(),
        ]);
    }

    public function regenerate(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        FileManipulator $fileManipulator,
    ): RedirectResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        collect(array_keys(AppMediaLibrary::assets()))
            ->map(fn (string $collection) => $mobileApp->getFirstMedia($collection))
            ->filter()
            ->each(fn (Media $media) => $fileManipulator->createDerivedFiles(
                media: $media,
                onlyMissing: true,
            ));

        return back()->with('status', 'Missing media variants queued for generation.');
    }

    /** @return array{name: string, size: int, preview_url: string, download_url: string}|null */
    private function source(Team $team, MobileApp $app, string $collection): ?array
    {
        $media = $app->getFirstMedia($collection);

        if (! $media) {
            return null;
        }

        return [
            'name' => $media->file_name,
            'size' => $media->size,
            'preview_url' => route('accounts.apps.assets.preview', [$team, $app, $collection]),
            'download_url' => route('accounts.apps.assets.download', [$team, $app, $collection]),
        ];
    }

    /**
     * @param  array{name: string, label: string, width: int, height: int}  $variant
     * @return array{name: string, label: string, width: int, height: int, ready: bool, preview_url: string, download_url: string}
     */
    private function variant(Team $team, MobileApp $app, string $collection, array $variant): array
    {
        /** @var Media|null $media */
        $media = $app->getFirstMedia($collection);

        return [
            ...$variant,
            'ready' => $media?->hasGeneratedConversion($variant['name']) ?? false,
            'preview_url' => route('accounts.apps.assets.conversions.preview', [
                $team, $app, $collection, $variant['name'],
            ]),
            'download_url' => route('accounts.apps.assets.conversions.download', [
                $team, $app, $collection, $variant['name'],
            ]),
        ];
    }
}
