<?php

namespace App\Support\Apps;

class AppFilePreview
{
    /** @var list<string> */
    private const IMAGE_MIME_TYPES = [
        'image/avif',
        'image/bmp',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/svg+xml',
        'image/webp',
    ];

    /** @var list<string> */
    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/ogg',
        'video/quicktime',
        'video/webm',
        'video/x-m4v',
    ];

    public static function type(?string $mimeType): ?string
    {
        $mimeType = strtolower((string) $mimeType);

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            return 'image';
        }

        if (in_array($mimeType, self::VIDEO_MIME_TYPES, true)) {
            return 'video';
        }

        return null;
    }
}
