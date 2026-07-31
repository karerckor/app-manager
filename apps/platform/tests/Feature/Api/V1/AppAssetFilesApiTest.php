<?php

namespace Tests\Feature\Api\V1;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AppAssetFilesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_map_of_ready_asset_download_urls(): void
    {
        Queue::fake();
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $icon = $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');
        $app->addMedia(UploadedFile::fake()->image('splash.png', 320, 480))
            ->toMediaCollection('splash_icon');
        $this->createConversion($icon, 'ios-icon-40', 40, 40);
        $token = $user->createTeamApiKey($team, 'Assets reader', ['assets:read'])->plainTextToken;
        $this->withToken($token);

        $response = $this->getJson(route('api.v1.apps.assets.files.index', [$team, $app]))
            ->assertOk();

        $files = $response->json('data');
        $this->assertSame([
            'icon.original' => route('api.v1.apps.assets.files.show', [
                $team, $app, 'icon', 'original',
            ]),
            'icon.ios-icon-40' => route('api.v1.apps.assets.files.show', [
                $team, $app, 'icon', 'ios-icon-40',
            ]),
            'splashscreen.original' => route('api.v1.apps.assets.files.show', [
                $team, $app, 'splashscreen', 'original',
            ]),
        ], $files);
    }

    public function test_map_urls_download_originals_and_generated_conversions(): void
    {
        Queue::fake();
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $media = $app->addMedia(UploadedFile::fake()->image('icon.png', 1024, 1024))
            ->toMediaCollection('app_icon');
        $this->createConversion($media, 'android-icon-mdpi', 48, 48);
        $token = $user->createTeamApiKey($team, 'Assets reader', ['assets:read'])->plainTextToken;
        $this->withToken($token);

        $files = $this->getJson(route('api.v1.apps.assets.files.index', [$team, $app]))
            ->assertOk()
            ->json('data');

        $this->get($files['icon.original'], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertDownload('icon.png');
        $this->get($files['icon.android-icon-mdpi'], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertDownload('android-icon-mdpi.png');
        $this->get(route('api.v1.apps.assets.files.show', [
            $team, $app, 'icon', 'ios-icon-40',
        ]))->assertNotFound();
    }

    public function test_empty_app_returns_an_empty_file_map(): void
    {
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $token = $user->createTeamApiKey($team, 'Assets reader', ['assets:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson(route('api.v1.apps.assets.files.index', [$team, $app]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    private function createConversion(Media $media, string $conversion, int $width, int $height): void
    {
        $file = UploadedFile::fake()->image("{$conversion}.png", $width, $height);
        $disk = $media->conversions_disk ?: $media->disk;

        Storage::disk($disk)->put(
            $media->getPathRelativeToRoot($conversion),
            (string) file_get_contents($file->getRealPath()),
        );
        $media->markAsConversionGenerated($conversion);
    }

    /** @return array{User, Team} */
    private function teamMember(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        return [$user, $team];
    }
}
