<?php

namespace App\Support\Builds;

use App\Models\Credential;
use App\Models\MobileApp;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BuildSnapshotFactory
{
    public const SCHEMA_VERSION = 1;

    public function make(MobileApp $mobileApp, array $context): array
    {
        $target = $context['target'];
        $profile = $context['profile'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => [
                'id' => $mobileApp->id,
                'slug' => $mobileApp->slug,
                'name' => $mobileApp->name,
                'description' => $mobileApp->description,
            ],
            'target' => [
                'id' => $target->id,
                'platform' => $target->platform->value,
                'identifier' => $target->identifier,
                'name' => $target->name,
                'version' => $target->version,
                'version_code' => $target->version_code,
                'build_number' => $target->build_number,
            ],
            'profile' => [
                ...$context['profile_definition'],
                'id' => $profile?->id,
                'name' => $profile?->name,
            ],
            'assets' => [
                'icon' => $this->media($mobileApp->getFirstMedia('app_icon')),
                'splashscreen' => $this->media($mobileApp->getFirstMedia('splash_icon')),
            ],
            'credentials' => $context['credentials']
                ->map(fn (Credential $credential) => [
                    'id' => $credential->id,
                    'type' => $credential->type->value,
                    'name' => $credential->name,
                    'expires_at' => $credential->expires_at?->toIso8601String(),
                    'updated_at' => $credential->updated_at?->toIso8601String(),
                    'file' => $this->media($credential->getFirstMedia('file')),
                ])
                ->values()
                ->all(),
        ];
    }

    private function media(?Media $media): ?array
    {
        if (! $media) {
            return null;
        }

        return [
            'id' => $media->id,
            'name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'sha256' => hash_file('sha256', $media->getPath()) ?: null,
        ];
    }
}
