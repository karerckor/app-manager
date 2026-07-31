<?php

namespace Tests\Unit;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\Artifact;
use App\Models\BuildRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactWebhookPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_events_are_subscribable(): void
    {
        $this->assertContains('artifact.created', WebhookEventType::subscribableValues());
        $this->assertContains('artifact.deleted', WebhookEventType::subscribableValues());
    }

    public function test_payload_includes_file_and_build_context(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $build = BuildRecord::factory()->create();
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $build->team_id,
            'name' => 'release.apk',
            'metadata' => ['git_sha' => 'deadbeef'],
        ]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 10, 'application/vnd.android.package-archive'))
            ->toMediaCollection('file');

        // Update mime_type since fake filesystem detection returns 'application/x-empty'
        $media = $artifact->getFirstMedia('file');
        $media->update(['mime_type' => 'application/vnd.android.package-archive']);

        $payload = WebhookPayload::artifact($artifact->refresh());

        $this->assertSame($artifact->id, $payload['id']);
        $this->assertSame($build->id, $payload['build_record_id']);
        $this->assertSame($build->mobile_app_id, $payload['mobile_app_id']);
        $this->assertSame('release.apk', $payload['name']);
        $this->assertSame('deadbeef', $payload['metadata']['git_sha']);
        $this->assertSame('release.apk', $payload['file']['name']);
        $this->assertSame('application/vnd.android.package-archive', $payload['file']['mime_type']);
    }
}
