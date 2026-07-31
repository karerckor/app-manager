<?php

namespace Tests\Unit;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventFilterContext;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AppFileWebhookPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_file_events_are_subscribable(): void
    {
        $this->assertContains('app_file.created', WebhookEventType::subscribableValues());
        $this->assertContains('app_file.deleted', WebhookEventType::subscribableValues());
    }

    public function test_payload_describes_the_attached_file(): void
    {
        $media = $this->attachedFile();

        // Update mime_type since fake filesystem detection returns 'application/x-empty'
        $media->update(['mime_type' => 'application/pdf']);

        $payload = WebhookPayload::appFile($media);

        $this->assertSame($media->uuid, $payload['id']);
        $this->assertSame($media->model_id, $payload['mobile_app_id']);
        $this->assertSame('notes.pdf', $payload['name']);
        $this->assertSame('application/pdf', $payload['mime_type']);
        $this->assertSame($media->size, $payload['size']);
        $this->assertSame(['channel' => 'beta'], $payload['metadata']);
        $this->assertNotNull($payload['created_at']);
    }

    public function test_filter_context_resolves_the_owning_app(): void
    {
        $media = $this->attachedFile();

        $context = app(WebhookEventFilterContext::class)->for($media);

        $this->assertSame([$media->model_id], $context['mobile_app_ids']);
        $this->assertNull($context['build_status']);
    }

    private function attachedFile(): Media
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();

        return $app->addMedia(UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'))
            ->withCustomProperties(['metadata' => ['channel' => 'beta']])
            ->toMediaCollection('files');
    }
}
