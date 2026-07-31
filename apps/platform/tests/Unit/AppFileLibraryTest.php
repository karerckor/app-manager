<?php

namespace Tests\Unit;

use App\Enums\WebhookEventType;
use App\Models\MobileApp;
use App\Support\Apps\AppFileLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppFileLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_stores_the_file_with_metadata_and_records_an_event(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $library = app(AppFileLibrary::class);

        $media = $library->attach(
            $app,
            UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
            ['channel' => 'beta'],
        );

        $this->assertSame('files', $media->collection_name);
        $this->assertSame('notes.pdf', $media->file_name);
        $this->assertSame(['channel' => 'beta'], $media->getCustomProperty('metadata'));
        $this->assertCount(1, $library->query($app)->get());
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $app->team_id,
            'event_type' => WebhookEventType::AppFileCreated->value,
            'aggregate_id' => $media->getKey(),
        ]);
    }

    public function test_attach_rejects_a_duplicate_file_name(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $library = app(AppFileLibrary::class);
        $library->attach($app, UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'));

        try {
            $library->attach($app, UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'));
            $this->fail('Expected a ValidationException for the duplicate file name.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['file' => ['A file named notes.pdf is already attached.']],
                $e->errors(),
            );
        }

        $this->assertCount(1, $library->query($app)->get());
    }

    public function test_find_by_name_matches_exactly(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $library = app(AppFileLibrary::class);
        $media = $library->attach($app, UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'));

        $this->assertSame($media->getKey(), $library->findByName($app, 'notes.pdf')?->getKey());
        $this->assertNull($library->findByName($app, 'Notes.pdf'));
        $this->assertNull($library->findByName($app, 'missing.pdf'));
        $this->assertTrue($library->belongsToApp($app, $media));
        $this->assertFalse($library->belongsToApp(MobileApp::factory()->create(), $media));
    }

    public function test_update_metadata_sets_and_clears_the_property(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $library = app(AppFileLibrary::class);
        $media = $library->attach($app, UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'));

        $library->updateMetadata($media, ['channel' => 'stable']);
        $this->assertSame(['channel' => 'stable'], $media->fresh()->getCustomProperty('metadata'));

        $library->updateMetadata($media, null);
        $this->assertNull($media->fresh()->getCustomProperty('metadata'));
    }

    public function test_detach_deletes_the_file_and_records_an_event(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $library = app(AppFileLibrary::class);
        $media = $library->attach($app, UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'));

        $library->detach($media);

        $this->assertDatabaseMissing('media', ['id' => $media->getKey()]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $app->team_id,
            'event_type' => WebhookEventType::AppFileDeleted->value,
            'aggregate_id' => $media->getKey(),
        ]);
    }
}
