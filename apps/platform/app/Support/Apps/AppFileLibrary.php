<?php

namespace App\Support\Apps;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use App\Support\Audit\AppFileAuditRecorder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppFileLibrary
{
    public const COLLECTION = 'files';

    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly AppFileAuditRecorder $audits,
    ) {}

    public function query(MobileApp $app): MorphMany
    {
        return $app->media()->where('collection_name', self::COLLECTION);
    }

    public function findByName(MobileApp $app, string $name): ?Media
    {
        return $this->query($app)
            ->orderBy('id')
            ->get()
            ->first(fn (Media $media) => hash_equals($media->file_name, $name));
    }

    public function belongsToApp(MobileApp $app, Media $media): bool
    {
        return $media->model_type === $app->getMorphClass()
            && (string) $media->model_id === (string) $app->getKey()
            && $media->collection_name === self::COLLECTION;
    }

    /** @param array<string, mixed>|null $metadata */
    public function attach(MobileApp $app, UploadedFile $file, ?array $metadata = null): Media
    {
        $adder = $app->addMedia($file);

        if ($metadata !== null) {
            $adder->withCustomProperties(['metadata' => $metadata]);
        }

        $media = $adder->toMediaCollection(self::COLLECTION);
        $duplicate = $this->query($app)->get()->first(
            fn (Media $candidate) => $candidate->getKey() !== $media->getKey()
                && hash_equals($candidate->file_name, $media->file_name),
        );

        if ($duplicate) {
            $fileName = $media->file_name;
            $media->delete();

            throw ValidationException::withMessages([
                'file' => "A file named {$fileName} is already attached.",
            ]);
        }

        $this->audits->created($app, $media);
        $this->recordWebhook($app, WebhookEventType::AppFileCreated, $media);

        return $media;
    }

    /** @param array<string, mixed>|null $metadata */
    public function updateMetadata(Media $media, ?array $metadata): Media
    {
        $media->loadMissing('model');
        $app = $media->model;
        $oldMetadata = $media->getCustomProperty('metadata');

        if ($metadata === null) {
            $media->forgetCustomProperty('metadata');
        } else {
            $media->setCustomProperty('metadata', $metadata);
        }

        $media->save();

        if ($app instanceof MobileApp) {
            $this->audits->metadataUpdated($app, $media, $oldMetadata, $metadata);
        }

        return $media;
    }

    public function detach(Media $media): void
    {
        $media->loadMissing('model');
        $app = $media->model;
        $media->delete();

        if ($app instanceof MobileApp) {
            $this->audits->deleted($app, $media);
            $this->recordWebhook($app, WebhookEventType::AppFileDeleted, $media);
        }
    }

    private function recordWebhook(MobileApp $app, WebhookEventType $event, Media $media): void
    {
        $this->webhookEvents->record(
            $app->loadMissing('team')->team,
            $event,
            $media,
            WebhookPayload::appFile($media),
        );
    }
}
