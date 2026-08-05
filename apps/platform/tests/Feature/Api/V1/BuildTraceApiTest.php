<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildTraceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ci_can_create_complete_and_read_immutable_build_trace(): void
    {
        Storage::fake('public');
        [$user, $team, $app, $target] = $this->application();
        $profile = $target->profiles()->create([
            'type' => ProfileType::Production,
            'name' => 'Production',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'name' => 'Production keystore',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);
        $credential->addMediaFromString('keystore-bytes')
            ->usingFileName('release.jks')
            ->toMediaCollection('file');
        $profile->credentials()->attach($credential);
        $app->addMediaFromString('icon-bytes')
            ->usingFileName('icon.png')
            ->toMediaCollection('app_icon');
        $this->authenticate($user);

        $payload = [
            'platform' => Platform::Android->value,
            'profile_type' => ProfileType::Production->value,
            'external_id' => 'customer-build-42',
            'commit_sha' => str_repeat('a', 40),
        ];
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds";

        $created = $this->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'customer-build-42')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.context_schema_version', 1)
            ->assertJsonPath('data.context_snapshot.app.slug', $app->slug)
            ->assertJsonPath('data.context_snapshot.target.identifier', $target->identifier)
            ->assertJsonPath('data.context_snapshot.credentials.0.id', $credential->id)
            ->assertJsonPath(
                'data.context_snapshot.credentials.0.file.sha256',
                hash('sha256', 'keystore-bytes'),
            );

        $this->assertStringNotContainsString('store-secret', $created->getContent());
        $this->assertStringNotContainsString('key-secret', $created->getContent());
        $this->assertArrayNotHasKey('payload', $created->json('data.context_snapshot.credentials.0'));

        $buildId = $created->json('data.id');
        $this->getJson("{$url}/external/customer-build-42")
            ->assertOk()
            ->assertJsonPath('data.id', $buildId)
            ->assertJsonPath('data.context_snapshot.target.version_code', 20300);
        $this->getJson("{$url}/{$buildId}")
            ->assertOk()
            ->assertJsonPath('data.id', $buildId);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', $buildId);

        $this->postJson("{$url}/{$buildId}/start", [
            'ci_provider' => 'github',
            'ci_run_id' => '12345',
            'ci_run_url' => 'https://example.test/runs/12345',
        ])->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.ci.run_id', '12345')
            ->assertJsonPath('data.started_at', fn ($value) => filled($value));
        $this->postJson("{$url}/{$buildId}/start", [
            'ci_provider' => 'github',
            'ci_run_id' => 'another-runner',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('ci_run_id');

        $logsUrl = "{$url}/{$buildId}/logs";
        $entries = [
            ['sequence' => 1, 'stream' => 'system', 'message' => 'Build claimed by runner'],
            ['sequence' => 2, 'stream' => 'stdout', 'message' => 'Compiling release bundle'],
            ['sequence' => 3, 'stream' => 'stderr', 'message' => 'A recoverable warning'],
        ];
        $this->postJson($logsUrl, ['entries' => $entries])
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', 3)
            ->assertJsonPath('data.last_sequence', 3);
        $this->postJson($logsUrl, ['entries' => $entries])
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', 0);
        $this->getJson("{$logsUrl}?after_sequence=1")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sequence', 2)
            ->assertJsonPath('data.1.level', 'error');

        $this->patchJson("{$url}/{$buildId}", [
            'status' => 'completed',
            'artifact_url' => 'https://example.test/artifacts/app.aab',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.artifact_url', 'https://example.test/artifacts/app.aab')
            ->assertJsonPath('data.finished_at', fn ($value) => filled($value));

        $this->patchJson("{$url}/{$buildId}", ['status' => 'failed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_external_id_creation_is_idempotent_and_scoped_to_one_app(): void
    {
        [$user, $team, $app] = $this->application();
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $otherApp->id,
            'platform' => Platform::Android,
            'is_default' => true,
        ]);
        $this->authenticate($user);
        $payload = [
            'platform' => Platform::Android->value,
            'profile_type' => ProfileType::Production->value,
            'external_id' => 'customer-build-42',
        ];
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds";

        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('build_records', 1);

        $this->postJson("/api/v1/{$team->slug}/apps/{$otherApp->slug}/builds", $payload)
            ->assertConflict();
        $this->getJson("/api/v1/{$team->slug}/apps/{$otherApp->slug}/builds/{$id}")
            ->assertNotFound();
    }

    public function test_context_snapshot_cannot_be_replaced(): void
    {
        [$user, $team, $app] = $this->application();
        $this->authenticate($user);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds";
        $buildId = $this->postJson($url, [
            'platform' => Platform::Android->value,
            'profile_type' => ProfileType::Production->value,
        ])->assertCreated()->json('data.id');

        $this->patchJson("{$url}/{$buildId}", [
            'status' => 'completed',
            'context_snapshot' => ['tampered' => true],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('context_snapshot');
        $this->assertDatabaseHas('build_records', ['id' => $buildId, 'status' => 'pending']);
    }

    public function test_cancelled_pending_build_cannot_be_started_by_a_runner(): void
    {
        [$user, $team, $app] = $this->application();
        $this->authenticate($user);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds";
        $buildId = $this->postJson($url, [
            'external_id' => 'cancel-before-runner',
            'platform' => Platform::Android->value,
            'profile_type' => ProfileType::Production->value,
        ])->assertCreated()->json('data.id');

        $this->patchJson("{$url}/{$buildId}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson("{$url}/{$buildId}/start", [
            'ci_provider' => 'github',
            'ci_run_id' => 'late-runner',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.started_at', null);
        $this->assertDatabaseHas('build_records', [
            'id' => $buildId,
            'status' => 'cancelled',
            'ci_run_id' => null,
            'started_at' => null,
        ]);
    }

    public function test_viewer_cannot_report_builds(): void
    {
        [, $team, $app] = $this->application();
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        $this->authenticate($viewer);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds";

        $this->postJson($url, [
            'platform' => Platform::Android->value,
            'profile_type' => ProfileType::Production->value,
        ])->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_claim_log_or_cancel_a_build(): void
    {
        [, $team, $app, $target] = $this->application();
        $build = BuildRecord::create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $target->id,
            'external_id' => 'viewer-build',
            'profile_type' => ProfileType::Production,
            'status' => 'pending',
            'context_schema_version' => 1,
            'context_snapshot' => [
                'target' => ['identifier' => $target->identifier],
                'profile' => ['value' => ProfileType::Production->value],
            ],
        ]);
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        $this->authenticate($viewer);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}";

        $this->getJson($url)->assertOk();
        $this->getJson("{$url}/logs")->assertOk();
        $this->postJson("{$url}/start", [
            'ci_provider' => 'github',
            'ci_run_id' => 'viewer-run',
        ])->assertForbidden();
        $this->postJson("{$url}/logs", [
            'entries' => [['sequence' => 1, 'message' => 'Forbidden']],
        ])->assertForbidden();
        $this->patchJson($url, ['status' => 'cancelled'])->assertForbidden();
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $target = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
            'version' => '2.3.0',
            'version_code' => 20300,
            'is_default' => true,
        ]);

        return [$user, $team, $app, $target];
    }

    private function authenticate(User $user): void
    {
        $this->withToken($user->createToken('CI')->plainTextToken);
    }
}
