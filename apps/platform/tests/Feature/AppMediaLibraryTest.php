<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\TestCase;

class AppMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_library_page_lists_ios_and_android_variants(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->get(route('accounts.apps.media.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Media/Index')
                ->where('active', 'media')
                ->where('app.urls.media_page', route('accounts.apps.media.index', [$team, $app]))
                ->where('fileLibrary.upload_url', route('accounts.apps.media.files.store', [$team, $app]))
                ->where('fileLibrary.files', [])
                ->where('mediaLibrary.0.collection', 'app_icon')
                ->where('mediaLibrary.0.source', null)
                ->where('mediaLibrary.0.platforms.0.label', 'iOS')
                ->where('mediaLibrary.0.platforms.1.label', 'Android')
                ->where('mediaLibrary.1.collection', 'splash_icon')
                ->where('mediaLibrary.1.platforms.0.label', 'iOS')
                ->where('mediaLibrary.1.platforms.1.label', 'Android')
                ->has('mediaLibrary.0.platforms.0.variants', 11)
                ->has('mediaLibrary.0.platforms.1.variants', 6)
                ->has('mediaLibrary.1.platforms.0.variants', 12)
                ->has('mediaLibrary.1.platforms.1.variants', 5));
    }

    public function test_any_file_can_be_attached_with_json_metadata(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(
            route('accounts.apps.media.files.store', [$team, $app]),
            [
                'file' => UploadedFile::fake()->create('release-bundle.tar.gz', 24, 'application/gzip'),
                'metadata' => '{"channel":"internal","version":"2.4.0"}',
            ],
        )->assertRedirect()
            ->assertSessionHas('status', 'File uploaded.');

        $media = $app->fresh()->getFirstMedia('files');

        $this->assertNotNull($media);
        $this->assertSame('release-bundle.tar.gz', $media->file_name);
        $this->assertNotEmpty($media->mime_type);
        $this->assertSame([
            'channel' => 'internal',
            'version' => '2.4.0',
        ], $media->getCustomProperty('metadata'));
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());

        $this->actingAs($user)
            ->get(route('accounts.apps.media.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('fileLibrary.files.0.id', $media->id)
                ->where('fileLibrary.files.0.name', 'release-bundle.tar.gz')
                ->where('fileLibrary.files.0.extension', 'gz')
                ->where('fileLibrary.files.0.preview_type', null)
                ->where('fileLibrary.files.0.urls.preview', null)
                ->where('fileLibrary.files.0.metadata.channel', 'internal')
                ->where(
                    'fileLibrary.files.0.urls.download',
                    route('accounts.apps.media.files.download', [
                        'team' => $team,
                        'mobileApp' => $app,
                        'fileName' => $media->file_name,
                    ]),
                ));
    }

    public function test_browser_safe_images_and_videos_can_be_previewed_inline(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $image = $app->addMedia(UploadedFile::fake()->image('animation.gif', 32, 32))
            ->toMediaCollection('files');
        $video = $app->addMedia(UploadedFile::fake()->create('preview.mp4', 8))
            ->toMediaCollection('files');
        $video->update(['mime_type' => 'video/mp4']);
        $text = $app->addMedia(UploadedFile::fake()->create('notes.txt', 2))
            ->toMediaCollection('files');
        $image->update(['created_at' => now()->subMinutes(2)]);
        $video->update(['created_at' => now()->subMinute()]);
        $text->update(['created_at' => now()]);

        $this->actingAs($user)
            ->get(route('accounts.apps.media.files.preview', [$team, $app, $image]))
            ->assertSuccessful()
            ->assertHeader('content-type', 'image/gif')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-security-policy');
        $this->get(route('accounts.apps.media.files.preview', [$team, $app, $video]))
            ->assertSuccessful()
            ->assertHeader('content-type', 'video/mp4');
        $this->get(route('accounts.apps.media.files.preview', [$team, $app, $text]))
            ->assertNotFound();

        $this->get(route('accounts.apps.media.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('fileLibrary.files.0.preview_type', null)
                ->where('fileLibrary.files.1.preview_type', 'video')
                ->where('fileLibrary.files.1.extension', 'mp4')
                ->where('fileLibrary.files.2.preview_type', 'image')
                ->where('fileLibrary.files.2.extension', 'gif'));
    }

    public function test_attached_files_do_not_replace_each_other(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        foreach (['notes.txt', 'settings.json'] as $name) {
            $this->actingAs($user)->post(
                route('accounts.apps.media.files.store', [$team, $app]),
                ['file' => UploadedFile::fake()->create($name, 2)],
            )->assertRedirect();
        }

        $this->assertSame(
            ['notes.txt', 'settings.json'],
            $app->fresh()->getMedia('files')->pluck('file_name')->all(),
        );
    }

    public function test_attached_file_names_are_unique_within_an_app(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(
            route('accounts.apps.media.files.store', [$team, $app]),
            ['file' => UploadedFile::fake()->create('settings.json', 2)],
        )->assertRedirect();
        $originalMediaId = $app->fresh()->getFirstMedia('files')->getKey();

        $this->post(
            route('accounts.apps.media.files.store', [$team, $app]),
            ['file' => UploadedFile::fake()->create('settings.json', 3)],
        )->assertSessionHasErrors('file');

        $files = $app->fresh()->getMedia('files');

        $this->assertCount(1, $files);
        $this->assertSame('settings.json', $files->first()->file_name);
        $this->assertSame($originalMediaId, $files->first()->getKey());
    }

    public function test_file_metadata_must_be_a_json_object(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(
            route('accounts.apps.media.files.store', [$team, $app]),
            [
                'file' => UploadedFile::fake()->create('notes.txt', 2),
                'metadata' => '["not","an","object"]',
            ],
        )->assertSessionHasErrors('metadata');

        $this->assertCount(0, $app->fresh()->getMedia('files'));
    }

    public function test_attached_file_can_be_downloaded_and_its_metadata_updated(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $media = $app->addMedia(UploadedFile::fake()->create('notes.txt', 2, 'text/plain'))
            ->withCustomProperties(['metadata' => ['version' => 1]])
            ->toMediaCollection('files');

        $this->actingAs($user)
            ->patch(route('accounts.apps.media.files.update', [$team, $app, $media]), [
                'metadata' => '{"version":2,"owner":"mobile"}',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'File metadata updated.');

        $this->assertSame([
            'version' => 2,
            'owner' => 'mobile',
        ], $media->fresh()->getCustomProperty('metadata'));

        $this->actingAs($user)
            ->get(route('accounts.apps.media.files.download', [
                'team' => $team,
                'mobileApp' => $app,
                'fileName' => $media->file_name,
            ]))
            ->assertSuccessful()
            ->assertHeader('content-type', $media->mime_type)
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('notes.txt');

        $this->assertStringEndsWith(
            '/media/files/notes.txt',
            route('accounts.apps.media.files.download', [
                'team' => $team,
                'mobileApp' => $app,
                'fileName' => $media->file_name,
            ]),
        );
    }

    public function test_attached_file_can_be_deleted_without_affecting_media_assets(): void
    {
        Storage::fake(config('media-library.disk_name'));
        Queue::fake();
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->addMedia(UploadedFile::fake()->image('icon.png'))->toMediaCollection('app_icon');
        $media = $app->addMedia(UploadedFile::fake()->create('notes.txt', 2))->toMediaCollection('files');
        $path = $media->getPathRelativeToRoot();
        $disk = $media->disk;

        $this->actingAs($user)
            ->delete(route('accounts.apps.media.files.destroy', [$team, $app, $media]))
            ->assertRedirect()
            ->assertSessionHas('status', 'File deleted.');

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk($disk)->assertMissing($path);
        $this->assertNotNull($app->fresh()->getFirstMedia('app_icon'));
    }

    public function test_file_from_another_app_cannot_be_accessed_through_the_current_app(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $media = $otherApp->addMedia(UploadedFile::fake()->create('private.txt', 2))
            ->toMediaCollection('files');

        $this->actingAs($user)
            ->get(route('accounts.apps.media.files.download', [
                'team' => $team,
                'mobileApp' => $app,
                'fileName' => $media->file_name,
            ]))
            ->assertNotFound();
        $this->delete(route('accounts.apps.media.files.destroy', [$team, $app, $media]))
            ->assertNotFound();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_upload_queues_only_the_conversions_for_the_asset_collection(): void
    {
        Queue::fake();
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->post(
            route('accounts.apps.assets.upload', [$team, $app, 'app_icon']),
            ['file' => UploadedFile::fake()->image('icon.png', 1024, 1024)],
        )->assertRedirect();

        $media = $app->fresh()->getFirstMedia('app_icon');
        $this->assertNotNull($media);

        $conversions = ConversionCollection::createForMedia($media)
            ->getConversions('app_icon')
            ->map->getName();

        $this->assertContains('ios-icon-1024', $conversions);
        $this->assertContains('android-icon-play-store', $conversions);
        $this->assertNotContains('ios-splash-1290x2796', $conversions);
        Queue::assertPushed(PerformConversionsJob::class);
    }

    public function test_icon_conversions_are_generated_at_the_registered_pixel_sizes(): void
    {
        config(['media-library.queue_connection_name' => 'sync']);
        Storage::fake(config('media-library.disk_name'));
        $app = MobileApp::factory()->create();
        $media = $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');

        $this->assertTrue($media->fresh()->hasGeneratedConversion('ios-icon-40'));
        $this->assertTrue($media->fresh()->hasGeneratedConversion('android-icon-play-store'));
        $this->assertSame(
            [40, 40],
            array_slice(getimagesize($media->getPath('ios-icon-40')), 0, 2),
        );
        $this->assertSame(
            [512, 512],
            array_slice(getimagesize($media->getPath('android-icon-play-store')), 0, 2),
        );
    }

    public function test_existing_sources_can_queue_missing_conversions(): void
    {
        Queue::fake();
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');
        Queue::fake();

        $this->actingAs($user)
            ->post(route('accounts.apps.media.regenerate', [$team, $app]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Missing media variants queued for generation.');

        Queue::assertPushed(PerformConversionsJob::class);
    }

    public function test_generated_conversion_can_be_previewed_and_downloaded(): void
    {
        Queue::fake();
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $media = $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');
        $converted = UploadedFile::fake()->image('converted.png', 40, 40);
        $contents = (string) file_get_contents($converted->getRealPath());
        $disk = $media->conversions_disk ?: $media->disk;
        $path = $media->getPathRelativeToRoot('ios-icon-40');
        Storage::disk($disk)->put($path, $contents);
        $media->markAsConversionGenerated('ios-icon-40');

        $preview = route('accounts.apps.assets.conversions.preview', [
            $team, $app, 'app_icon', 'ios-icon-40',
        ]);
        $download = route('accounts.apps.assets.conversions.download', [
            $team, $app, 'app_icon', 'ios-icon-40',
        ]);

        $this->actingAs($user)->get($preview)
            ->assertSuccessful()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->get($download)
            ->assertSuccessful()
            ->assertDownload('ios-icon-40.png');
        $this->get(route('accounts.apps.assets.conversions.preview', [
            $team, $app, 'splash_icon', 'ios-icon-40',
        ]))->assertNotFound();
    }

    public function test_media_library_is_forbidden_for_a_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.apps.media.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }

    /** @return array{User, Team} */
    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
