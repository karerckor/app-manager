<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Apps\AppFileLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AppFileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_files_newest_first_and_paginates(): void
    {
        [$user, $team, $app] = $this->context();
        $this->attach($app, 'first.pdf');
        $this->attach($app, 'second.pdf');
        $this->attach($app, 'third.pdf');
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->getJson($this->url($team, $app).'?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'third.pdf')
            ->assertJsonPath('data.1.name', 'second.pdf')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_show_returns_the_file_resource(): void
    {
        [$user, $team, $app] = $this->context();
        $media = $this->attach($app, 'notes.pdf', ['channel' => 'beta']);
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->getJson($this->url($team, $app).'/notes.pdf')
            ->assertOk()
            ->assertJsonPath('data.id', $media->uuid)
            ->assertJsonPath('data.name', 'notes.pdf')
            ->assertJsonPath('data.extension', 'pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size', $media->size)
            ->assertJsonPath('data.metadata.channel', 'beta')
            ->assertJsonPath('data.urls.content', route('api.v1.apps.files.content.show', [
                $team, $app, 'notes.pdf',
            ]));
    }

    public function test_missing_file_returns_not_found(): void
    {
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->getJson($this->url($team, $app).'/missing.pdf')->assertNotFound();
    }

    public function test_content_streams_the_file(): void
    {
        [$user, $team, $app] = $this->context();
        $this->attach($app, 'notes.pdf');
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->get($this->url($team, $app).'/notes.pdf/content')
            ->assertOk()
            ->assertDownload('notes.pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_read_requires_the_read_scope(): void
    {
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::AssetsRead]);

        $this->getJson($this->url($team, $app))->assertForbidden();
    }

    public function test_store_uploads_a_file_with_metadata_and_records_an_event(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::FilesWrite]);

        $this->post($this->url($team, $app), [
            'file' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
            'metadata' => json_encode(['channel' => 'beta']),
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'notes.pdf')
            ->assertJsonPath('data.metadata.channel', 'beta');

        $media = $app->refresh()->getMedia('files')->first();
        $this->assertNotNull($media);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppFileCreated->value,
            'aggregate_id' => $media->getKey(),
        ]);

        $audit = Audit::query()
            ->where('auditable_type', MobileApp::class)
            ->where('auditable_id', $app->id)
            ->where('event', 'file_created')
            ->sole();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('api', $audit->source);
        $this->assertNotNull($audit->api_key_id);
        $this->assertSame(['channel' => 'beta'], $audit->new_values['file_metadata']);
    }

    public function test_store_rejects_a_duplicate_name_and_invalid_metadata(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app] = $this->context();
        $this->attach($app, 'notes.pdf');
        $this->authenticate($user, $team, [ApiKeyScope::FilesWrite]);

        $this->postJson($this->url($team, $app), [
            'file' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'A file named notes.pdf is already attached.');

        $this->postJson($this->url($team, $app), [
            'file' => UploadedFile::fake()->create('other.pdf', 12, 'application/pdf'),
            'metadata' => '[1,2]',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.metadata.0', 'Metadata must be a JSON object.');
    }

    public function test_store_requires_the_write_scope(): void
    {
        [$user, $team, $app] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->postJson($this->url($team, $app), [
            'file' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_store_is_blocked_for_a_viewer_role(): void
    {
        [, $team, $app] = $this->context();
        $viewer = User::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);
        $this->authenticate($viewer, $team, [ApiKeyScope::FilesWrite]);

        $this->postJson($this->url($team, $app), [
            'file' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_update_replaces_and_clears_metadata(): void
    {
        [$user, $team, $app] = $this->context();
        $media = $this->attach($app, 'notes.pdf', ['channel' => 'beta']);
        $this->authenticate($user, $team, [ApiKeyScope::FilesWrite]);

        $this->patchJson($this->url($team, $app).'/notes.pdf', [
            'metadata' => ['channel' => 'stable'],
        ])
            ->assertOk()
            ->assertJsonPath('data.metadata.channel', 'stable');

        $updatedAudit = Audit::query()
            ->where('auditable_type', MobileApp::class)
            ->where('auditable_id', $app->id)
            ->where('event', 'file_metadata_updated')
            ->sole();

        $this->assertSame($user->id, $updatedAudit->user_id);
        $this->assertSame('api', $updatedAudit->source);
        $this->assertSame(['channel' => 'beta'], $updatedAudit->old_values['file_metadata']);
        $this->assertSame(['channel' => 'stable'], $updatedAudit->new_values['file_metadata']);

        $this->patchJson($this->url($team, $app).'/notes.pdf', ['metadata' => null])
            ->assertOk()
            ->assertJsonPath('data.metadata', null);

        $this->assertNull($media->fresh()->getCustomProperty('metadata'));
        $this->assertSame(2, Audit::query()
            ->where('auditable_type', MobileApp::class)
            ->where('auditable_id', $app->id)
            ->where('event', 'file_metadata_updated')
            ->count());
    }

    public function test_destroy_deletes_the_file_and_records_an_event(): void
    {
        [$user, $team, $app] = $this->context();
        $media = $this->attach($app, 'notes.pdf');
        $this->authenticate($user, $team, [ApiKeyScope::FilesWrite]);

        $this->deleteJson($this->url($team, $app).'/notes.pdf')->assertNoContent();

        $this->assertDatabaseMissing('media', ['id' => $media->getKey()]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppFileDeleted->value,
            'aggregate_id' => $media->getKey(),
        ]);

        $audit = Audit::query()
            ->where('auditable_type', MobileApp::class)
            ->where('auditable_id', $app->id)
            ->where('event', 'file_deleted')
            ->sole();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('api', $audit->source);
        $this->assertSame('notes.pdf', $audit->old_values['file_name']);
        $this->assertSame([], $audit->new_values);
    }

    public function test_files_are_tenant_isolated(): void
    {
        [$user, $team] = $this->context();
        [, $otherTeam, $otherApp] = $this->context();
        $this->attach($otherApp, 'foreign.pdf');
        $this->authenticate($user, $team, [ApiKeyScope::FilesRead]);

        $this->getJson("/api/v1/{$team->slug}/apps/{$otherApp->slug}/files")->assertNotFound();
        $this->getJson("/api/v1/{$otherTeam->slug}/apps/{$otherApp->slug}/files")->assertForbidden();
    }

    /** @param array<string, mixed>|null $metadata */
    private function attach(MobileApp $app, string $name, ?array $metadata = null): Media
    {
        Storage::fake(config('media-library.disk_name'));

        $media = app(AppFileLibrary::class)->attach(
            $app,
            UploadedFile::fake()->create($name, 12, 'application/pdf'),
            $metadata,
        );

        // Update mime_type since fake filesystem detection returns 'application/x-empty'
        $media->update(['mime_type' => 'application/pdf']);

        return $media->fresh();
    }

    /** @return array{0: User, 1: Team, 2: MobileApp} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user, $team, $app];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope) => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }

    private function url(Team $team, MobileApp $app): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}/files";
    }
}
