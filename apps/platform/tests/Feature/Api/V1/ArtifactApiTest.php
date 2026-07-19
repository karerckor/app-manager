<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_show_list_artifacts_for_a_build(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsRead]);
        $artifact = $this->makeArtifact($team, $build, 'release.apk');

        $this->getJson($this->url($team, $app, $build))
            ->assertOk()
            ->assertJsonPath('data.0.id', $artifact->id)
            ->assertJsonPath('data.0.name', 'release.apk')
            ->assertJsonPath('data.0.file.name', 'release.apk');

        $this->getJson($this->url($team, $app, $build).'/'.$artifact->id)
            ->assertOk()
            ->assertJsonPath('data.id', $artifact->id);
    }

    public function test_read_requires_the_read_scope(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::BuildsRead]);
        $this->getJson($this->url($team, $app, $build))->assertForbidden();
    }

    public function test_download_streams_the_file(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsRead]);
        $artifact = $this->makeArtifact($team, $build, 'release.apk');

        $this->get($this->url($team, $app, $build).'/'.$artifact->id.'/file')
            ->assertOk()
            ->assertDownload('release.apk');
    }

    public function test_artifacts_are_tenant_isolated(): void
    {
        [$user, $team, $app, $build] = $this->context();
        [, $otherTeam, , $otherBuild] = $this->context();
        $foreign = $this->makeArtifact($otherTeam, $otherBuild, 'foreign.apk');
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsRead]);

        $this->getJson($this->url($team, $app, $build).'/'.$foreign->id)->assertNotFound();
    }

    public function test_store_uploads_a_file_and_records_created_event(): void
    {
        Storage::fake(config('filesystems.default'));
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsWrite]);

        $response = $this->post($this->url($team, $app, $build), [
            'name' => 'release.apk',
            'metadata' => json_encode(['git_sha' => 'abc123']),
            'file' => UploadedFile::fake()->create('release.apk', 20),
        ])->assertCreated()
            ->assertJsonPath('data.name', 'release.apk')
            ->assertJsonPath('data.metadata.git_sha', 'abc123');

        $artifactId = $response->json('data.id');
        $this->assertNotNull(Artifact::find($artifactId)?->getFirstMedia('file'));
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::ArtifactCreated->value,
            'aggregate_id' => $artifactId,
        ]);
    }

    public function test_store_requires_the_write_scope(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsRead]);
        $this->post($this->url($team, $app, $build), [
            'name' => 'x.apk',
            'file' => UploadedFile::fake()->create('x.apk', 1),
        ])->assertForbidden();
    }

    private function makeArtifact(Team $team, BuildRecord $build, string $name): Artifact
    {
        Storage::fake(config('filesystems.default'));
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id, 'name' => $name]);
        $artifact->addMedia(UploadedFile::fake()->create($name, 10))->toMediaCollection('file');

        return $artifact;
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: BuildRecord} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);

        return [$user, $team, $app, $build];
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

    private function url(Team $team, MobileApp $app, BuildRecord $build): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/artifacts";
    }
}
