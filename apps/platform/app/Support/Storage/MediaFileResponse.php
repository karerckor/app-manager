<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaFileResponse
{
    /** @param array<string, string> $headers */
    public static function download(Media $media, array $headers = []): StreamedResponse
    {
        $headers += self::metadataHeaders($media);

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            $headers,
        );
    }

    /** @param array<string, string> $headers */
    public static function inline(Media $media, array $headers = []): StreamedResponse
    {
        $headers += self::metadataHeaders($media);

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            $headers,
        );
    }

    /** @param array<string, string> $headers */
    public static function downloadConversion(
        Media $media,
        string $conversion,
        string $downloadName,
        array $headers = [],
    ): StreamedResponse {
        $disk = $media->conversions_disk ?: $media->disk;
        $path = $media->getPathRelativeToRoot($conversion);
        $headers += self::conversionHeaders($disk, $path);

        return Storage::disk($disk)->download($path, $downloadName, $headers);
    }

    /** @param array<string, string> $headers */
    public static function inlineConversion(Media $media, string $conversion, array $headers = []): StreamedResponse
    {
        $disk = $media->conversions_disk ?: $media->disk;
        $path = $media->getPathRelativeToRoot($conversion);
        $headers += self::conversionHeaders($disk, $path);

        return Storage::disk($disk)->response($path, basename($path), $headers);
    }

    /** @return array<string, string> */
    private static function metadataHeaders(Media $media): array
    {
        return [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $media->size,
        ];
    }

    /** @return array<string, string> */
    private static function conversionHeaders(string $disk, string $path): array
    {
        $storage = Storage::disk($disk);

        return [
            'Content-Type' => $storage->mimeType($path) ?: 'application/octet-stream',
            'Content-Length' => (string) $storage->size($path),
        ];
    }
}
