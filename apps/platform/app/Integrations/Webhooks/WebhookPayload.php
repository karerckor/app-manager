<?php

namespace App\Integrations\Webhooks;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class WebhookPayload
{
    /** @return array<string, mixed> */
    public static function mobileApp(MobileApp $app): array
    {
        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'description' => $app->description,
            'metadata' => $app->metadata,
            'created_at' => $app->created_at?->toISOString(),
            'updated_at' => $app->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function appIdentifier(AppIdentifier $identifier): array
    {
        return [
            'id' => $identifier->id,
            'mobile_app_id' => $identifier->mobile_app_id,
            'platform' => $identifier->platform->value,
            'identifier' => $identifier->identifier,
            'name' => $identifier->name,
            'version' => $identifier->version,
            'version_code' => $identifier->version_code,
            'created_at' => $identifier->created_at?->toISOString(),
            'updated_at' => $identifier->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function credential(Credential $credential): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->type->value,
            'expires_at' => $credential->expires_at?->toISOString(),
            'created_at' => $credential->created_at?->toISOString(),
            'updated_at' => $credential->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function build(BuildRecord $build): array
    {
        $payload = [
            'id' => $build->id,
            'request_uuid' => $build->request_uuid,
            'mobile_app_id' => $build->mobile_app_id,
            'app_identifier_id' => $build->app_identifier_id,
            'profile_type' => $build->profile_type->value,
            'status' => $build->status->value,
            'reporting_mode' => $build->reporting_mode->value,
            'started_at' => $build->started_at?->toISOString(),
            'cancel_requested_at' => $build->cancel_requested_at?->toISOString(),
            'finished_at' => $build->finished_at?->toISOString(),
            'created_at' => $build->created_at?->toISOString(),
            'updated_at' => $build->updated_at?->toISOString(),
        ];

        if ($build->reporting_mode->value === 'eas') {
            $payload['expo_build_id'] = $build->expo_build_id;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function artifact(Artifact $artifact): array
    {
        $media = $artifact->getFirstMedia('file');

        return [
            'id' => $artifact->id,
            'build_record_id' => $artifact->build_record_id,
            'mobile_app_id' => $artifact->buildRecord?->mobile_app_id,
            'name' => $artifact->name,
            'metadata' => $artifact->metadata,
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'mime_type' => $media->mime_type,
            ] : null,
            'created_at' => $artifact->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function appFile(Media $media): array
    {
        return [
            'id' => $media->uuid,
            'mobile_app_id' => $media->model_id,
            'name' => $media->file_name,
            'mime_type' => $media->mime_type ?: 'application/octet-stream',
            'size' => $media->size,
            'metadata' => $media->getCustomProperty('metadata'),
            'created_at' => $media->created_at?->toISOString(),
        ];
    }
}
