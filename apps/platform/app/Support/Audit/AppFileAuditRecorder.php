<?php

namespace App\Support\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class AppFileAuditRecorder
{
    public function __construct(private readonly ActorContextManager $actorContext) {}

    public function created(MobileApp $app, Media $media): Audit
    {
        return $this->record(
            $app,
            'file_created',
            [],
            $this->snapshot($media, $media->getCustomProperty('metadata')),
        );
    }

    public function metadataUpdated(
        MobileApp $app,
        Media $media,
        mixed $oldMetadata,
        mixed $newMetadata,
    ): ?Audit {
        if ($oldMetadata === $newMetadata) {
            return null;
        }

        return $this->record(
            $app,
            'file_metadata_updated',
            $this->snapshot($media, $oldMetadata),
            $this->snapshot($media, $newMetadata),
        );
    }

    public function deleted(MobileApp $app, Media $media): Audit
    {
        return $this->record(
            $app,
            'file_deleted',
            $this->snapshot($media, $media->getCustomProperty('metadata')),
            [],
        );
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function record(MobileApp $app, string $event, array $oldValues, array $newValues): Audit
    {
        $context = $this->actorContext->current();

        return Audit::create([
            'user_type' => $context->originator ? User::class : null,
            'user_id' => $context->originator?->id,
            'event' => $event,
            'auditable_type' => MobileApp::class,
            'auditable_id' => $app->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $context->ip,
            'user_agent' => $context->userAgent,
            'source' => $context->source,
            'team_id' => $app->team_id,
            'api_key_id' => $context->apiKey?->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Media $media, mixed $metadata): array
    {
        return [
            'file_id' => (string) ($media->uuid ?: $media->getKey()),
            'file_name' => $media->file_name,
            'file_metadata' => $metadata,
        ];
    }
}
