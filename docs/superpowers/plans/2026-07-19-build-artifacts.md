# Build Artifacts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users attach named, metadata-tagged files (build artifacts) to a build via REST API, web UI, and a Fastlane action, with dual ACL and webhook events on create/delete.

**Architecture:** A new `Artifact` Eloquent model (Spatie MediaLibrary single-file, UUID PK) belongs to a `BuildRecord` and `Team`. Artifacts are immutable (create/list/show/download/delete). REST endpoints live under `/api/v1/{team}/apps/{app}/builds/{build}/artifacts` guarded by Sanctum abilities + `team.permission` middleware; web routes under `accounts.apps.builds.*` render into the existing `Apps/Show.svelte`. Creation/deletion record `artifact.created`/`artifact.deleted` webhook events. The Fastlane plugin gains a multipart upload path and an `app_manager_add_artifact` action.

**Tech Stack:** Laravel 13, PHP 8.4, Sanctum, Spatie MediaLibrary, Inertia.js + Svelte 5, PHPUnit 12; Ruby Fastlane plugin with RSpec.

## Global Constraints

- Models use `HasUuids` and the `#[Fillable([...])]` attribute (not `$fillable`).
- Every API route carries BOTH `abilities:<scope>` (Sanctum) AND `team.permission:<permission>` middleware.
- New scope→permission map: `artifacts:read` → `TeamPermission::View`; `artifacts:write` → `TeamPermission::ManageApplications`. No new `TeamPermission`.
- File storage: Spatie MediaLibrary collection `file` with `singleFile()`, disk = `config('filesystems.default')` (env `FILESYSTEM_DISK`). Upload limit `512000` KB (~500 MB) enforced via validation `max:512000`.
- Webhook recording goes through `App\Integrations\Webhooks\WebhookEventRecorder::record(Team, WebhookEventType, Model|string, array, ?User)`, always inside the same `DB::transaction`; delete payloads captured BEFORE deletion.
- Metadata is a free-form JSON object, nullable. In JSON requests it arrives as an object/array; in multipart it arrives as a JSON-encoded string — controllers normalize to array before storing.
- Laravel tests: class-based `extends Tests\TestCase`, `use RefreshDatabase`. Run with `php artisan test`. Work from `apps/platform`.
- Fastlane tests run with `bundle exec rspec` from `libs/fastlane-plugin`.
- Tenant isolation: resolve artifact through build → app → team; mismatches return 404 via `abort_unless`.
- Commit after each task's tests pass.

## File Structure

**Platform (`apps/platform`)**
- Create `app/Models/Artifact.php` — the model.
- Create `database/migrations/2026_07_19_000000_create_artifacts_table.php`.
- Create `database/factories/ArtifactFactory.php`.
- Modify `app/Enums/ApiKeyScope.php` — add scopes + mappings/labels/descriptions.
- Modify `app/Enums/WebhookEventType.php` — add `ArtifactCreated`, `ArtifactDeleted`.
- Modify `app/Integrations/Webhooks/WebhookPayload.php` — add `artifact()`.
- Create `app/Http/Resources/Api/V1/ArtifactResource.php`.
- Create `app/Http/Controllers/Api/V1/ArtifactController.php`.
- Create `app/Http/Controllers/BuildArtifactController.php` (web).
- Modify `routes/api.php` and `routes/web.php`.
- Modify `app/Http/Controllers/MobileAppController.php` (`show`).
- Modify `app/Models/BuildRecord.php` — `artifacts()` relation + media cleanup hook.
- Modify `resources/js/Pages/Apps/Show.svelte`.
- Create `tests/Feature/Api/V1/ArtifactApiTest.php`, `tests/Feature/BuildArtifactWebTest.php`; modify `tests/Feature/Api/V1/ApiKeyScopeTest.php` and `tests/Feature/Api/V1/ExistingRoutesAclTest.php` as needed.

**Fastlane (`libs/fastlane-plugin`)**
- Modify `lib/fastlane/plugin/app_manager/client.rb`.
- Modify `lib/fastlane/plugin/app_manager/helper/app_manager_helper.rb`.
- Create `lib/fastlane/plugin/app_manager/actions/app_manager_add_artifact_action.rb`.
- Modify `spec/client_spec.rb`, `spec/actions_spec.rb`, `README.md`.

---

### Task 1: Artifact model, migration, factory

**Files:**
- Create: `apps/platform/app/Models/Artifact.php`
- Create: `apps/platform/database/migrations/2026_07_19_000000_create_artifacts_table.php`
- Create: `apps/platform/database/factories/ArtifactFactory.php`
- Modify: `apps/platform/app/Models/BuildRecord.php`
- Test: `apps/platform/tests/Unit/ArtifactModelTest.php`

**Interfaces:**
- Produces: `App\Models\Artifact` with `HasUuids`, `InteractsWithMedia`, media collection `file` (singleFile); relations `team()`, `buildRecord()`; casts `metadata => 'array'`. Fillable: `team_id`, `build_record_id`, `name`, `metadata`.
- Produces: `BuildRecord::artifacts(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/ArtifactModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Artifact;
use App\Models\BuildRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_belongs_to_build_and_casts_metadata(): void
    {
        $build = BuildRecord::factory()->create();
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $build->team_id,
            'name' => 'app-release.apk',
            'metadata' => ['git_sha' => 'abc123', 'size_label' => '42MB'],
        ]);

        $this->assertSame($build->id, $artifact->buildRecord->id);
        $this->assertSame('abc123', $artifact->metadata['git_sha']);
        $this->assertTrue($build->artifacts->contains($artifact));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=ArtifactModelTest`
Expected: FAIL — `Class "App\Models\Artifact" not found`.

- [ ] **Step 3: Write the migration**

Create `apps/platform/database/migrations/2026_07_19_000000_create_artifacts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('build_record_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['build_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
```

- [ ] **Step 4: Write the model**

Create `apps/platform/app/Models/Artifact.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'build_record_id', 'name', 'metadata'])]
class Artifact extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function buildRecord(): BelongsTo
    {
        return $this->belongsTo(BuildRecord::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
```

- [ ] **Step 5: Add the factory**

Create `apps/platform/database/factories/ArtifactFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BuildRecord;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Artifact>
 */
class ArtifactFactory extends Factory
{
    public function definition(): array
    {
        $build = BuildRecord::factory();

        return [
            'team_id' => Team::factory(),
            'build_record_id' => $build,
            'name' => fake()->word().'.apk',
            'metadata' => null,
        ];
    }
}
```

- [ ] **Step 6: Add the `artifacts()` relation to BuildRecord**

In `apps/platform/app/Models/BuildRecord.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` (if missing) and this method alongside the other relations:

```php
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactModelTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add apps/platform/app/Models/Artifact.php apps/platform/app/Models/BuildRecord.php apps/platform/database/migrations/2026_07_19_000000_create_artifacts_table.php apps/platform/database/factories/ArtifactFactory.php apps/platform/tests/Unit/ArtifactModelTest.php
git commit -m "feat(artifacts): add Artifact model, migration and factory"
```

---

### Task 2: ACL scopes

**Files:**
- Modify: `apps/platform/app/Enums/ApiKeyScope.php`
- Test: `apps/platform/tests/Unit/ArtifactScopeTest.php`

**Interfaces:**
- Produces: `ApiKeyScope::ArtifactsRead` (`'artifacts:read'`) and `ApiKeyScope::ArtifactsWrite` (`'artifacts:write'`); `requiredPermission()` returns `View` / `ManageApplications` respectively; `resourceLabel()` returns `'Build artifacts'`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/ArtifactScopeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamPermission;
use Tests\TestCase;

class ArtifactScopeTest extends TestCase
{
    public function test_artifact_scopes_map_to_expected_permissions(): void
    {
        $this->assertContains('artifacts:read', ApiKeyScope::values());
        $this->assertContains('artifacts:write', ApiKeyScope::values());
        $this->assertSame(TeamPermission::View, ApiKeyScope::ArtifactsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageApplications, ApiKeyScope::ArtifactsWrite->requiredPermission());
        $this->assertSame('Build artifacts', ApiKeyScope::ArtifactsRead->resourceLabel());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=ArtifactScopeTest`
Expected: FAIL — undefined constant `ApiKeyScope::ArtifactsRead`.

- [ ] **Step 3: Add the enum cases**

In `apps/platform/app/Enums/ApiKeyScope.php`, add these two cases after `case LogsWrite = 'logs:write';`:

```php
    case ArtifactsRead = 'artifacts:read';
    case ArtifactsWrite = 'artifacts:write';
```

- [ ] **Step 4: Wire the mappings**

In `requiredPermission()`, add `self::ArtifactsRead` to the `View` arm and `self::ArtifactsWrite` to the `ManageApplications` arm:

```php
            self::AppsRead, self::IdentifiersRead, self::AssetsRead,
            self::CredentialsRead, self::BuildsRead, self::LogsRead,
            self::ArtifactsRead => TeamPermission::View,
            self::AppsWrite, self::IdentifiersWrite, self::AssetsWrite,
            self::BuildsWrite, self::LogsWrite,
            self::ArtifactsWrite => TeamPermission::ManageApplications,
```

In `resourceLabel()`, add to the match: `'artifacts' => 'Build artifacts',`.

In `description()`, add:

```php
            self::ArtifactsRead => 'View and download build artifacts.',
            self::ArtifactsWrite => 'Upload and delete build artifacts.',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactScopeTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Enums/ApiKeyScope.php apps/platform/tests/Unit/ArtifactScopeTest.php
git commit -m "feat(artifacts): add artifacts:read/write API key scopes"
```

---

### Task 3: Webhook event types + payload

**Files:**
- Modify: `apps/platform/app/Enums/WebhookEventType.php`
- Modify: `apps/platform/app/Integrations/Webhooks/WebhookPayload.php`
- Test: `apps/platform/tests/Unit/ArtifactWebhookPayloadTest.php`

**Interfaces:**
- Produces: `WebhookEventType::ArtifactCreated` (`'artifact.created'`), `WebhookEventType::ArtifactDeleted` (`'artifact.deleted'`) — automatically in `subscribableValues()`.
- Produces: `WebhookPayload::artifact(Artifact): array` with keys `id, build_record_id, mobile_app_id, name, metadata, file, created_at`; `file` is `null` or `{name, size, mime_type}`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/ArtifactWebhookPayloadTest.php`:

```php
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
        Storage::fake(config('filesystems.default'));
        $build = BuildRecord::factory()->create();
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $build->team_id,
            'name' => 'release.apk',
            'metadata' => ['git_sha' => 'deadbeef'],
        ]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 10, 'application/vnd.android.package-archive'))
            ->toMediaCollection('file');

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=ArtifactWebhookPayloadTest`
Expected: FAIL — undefined constant `WebhookEventType::ArtifactCreated`.

- [ ] **Step 3: Add event types**

In `apps/platform/app/Enums/WebhookEventType.php`, add after `case BuildCompleted = 'build.completed';`:

```php
    case ArtifactCreated = 'artifact.created';
    case ArtifactDeleted = 'artifact.deleted';
```

(No change needed to `subscribableValues()` — it derives from all cases except `WebhookTest`.)

- [ ] **Step 4: Add the payload builder**

In `apps/platform/app/Integrations/Webhooks/WebhookPayload.php`, add `use App\Models\Artifact;` to the imports and this method:

```php
    /** @return array<string, mixed> */
    public static function artifact(Artifact $artifact): array
    {
        $media = $artifact->getFirstMedia('file');

        return [
            'id' => $artifact->id,
            'build_record_id' => $artifact->build_record_id,
            'mobile_app_id' => $artifact->buildRecord?->mobile_app_id,
            'name' => $artifact->name,
            'metadata' => $artifact->metadata,
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'mime_type' => $media->mime_type,
            ] : null,
            'created_at' => $artifact->created_at?->toISOString(),
        ];
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactWebhookPayloadTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Enums/WebhookEventType.php apps/platform/app/Integrations/Webhooks/WebhookPayload.php apps/platform/tests/Unit/ArtifactWebhookPayloadTest.php
git commit -m "feat(artifacts): add artifact.created/deleted webhook events and payload"
```

---

### Task 4: API read endpoints (routes, resource, controller index/show/download)

**Files:**
- Create: `apps/platform/app/Http/Resources/Api/V1/ArtifactResource.php`
- Create: `apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php`
- Modify: `apps/platform/routes/api.php`
- Test: `apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php`

**Interfaces:**
- Consumes: `Artifact` (Task 1), `ApiKeyScope::ArtifactsRead` (Task 2).
- Produces: routes named `api.v1.apps.builds.artifacts.index`, `.show`, `.file.show`. `ArtifactResource` shape: `{id, name, metadata, build_record_id, file: {name, mime_type, size, url}|null, created_at}`. `ArtifactController::artifact(Team, MobileApp, BuildRecord, string): Artifact` (tenant-scoped resolver, used by later tasks).

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Models\Artifact;
use App\Models\AppIdentifier;
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=ArtifactApiTest`
Expected: FAIL — routes/controller not defined (404/500).

- [ ] **Step 3: Write the resource**

Create `apps/platform/app/Http/Resources/Api/V1/ArtifactResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->resource->getFirstMedia('file');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'metadata' => $this->metadata,
            'build_record_id' => $this->build_record_id,
            'file' => $media ? [
                'name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => route('api.v1.apps.builds.artifacts.file.show', [
                    'team' => $request->route('team'),
                    'mobileApp' => $request->route('mobileApp'),
                    'buildRecord' => $this->build_record_id,
                    'artifact' => $this->resource,
                ]),
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
```

- [ ] **Step 4: Write the controller (read actions)**

Create `apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArtifactResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArtifactController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): AnonymousResourceCollection
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        $artifacts = $buildRecord->artifacts()
            ->with('media')
            ->latest()
            ->paginate($this->perPage($request));

        return ArtifactResource::collection($artifacts);
    }

    public function show(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): ArtifactResource
    {
        return new ArtifactResource($this->artifact($team, $mobileApp, $buildRecord, $artifact));
    }

    public function download(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): BinaryFileResponse
    {
        $media = $this->artifact($team, $mobileApp, $buildRecord, $artifact)->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    protected function assertBuildBelongs(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
    }

    protected function artifact(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $id): Artifact
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        return $buildRecord->artifacts()
            ->with('media')
            ->whereKey($id)
            ->firstOrFail();
    }
}
```

- [ ] **Step 5: Register the read routes**

In `apps/platform/routes/api.php`, add `use App\Http\Controllers\Api\V1\ArtifactController;` and, inside the group after the build-log routes, add:

```php
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts', [ArtifactController::class, 'index'])->middleware(['abilities:artifacts:read', 'team.permission:view'])->name('apps.builds.artifacts.index');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [ArtifactController::class, 'show'])->middleware(['abilities:artifacts:read', 'team.permission:view'])->name('apps.builds.artifacts.show');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}/file', [ArtifactController::class, 'download'])->middleware(['abilities:artifacts:read', 'team.permission:view'])->name('apps.builds.artifacts.file.show');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/platform/app/Http/Resources/Api/V1/ArtifactResource.php apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php apps/platform/routes/api.php apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php
git commit -m "feat(artifacts): add API read endpoints (index/show/download)"
```

---

### Task 5: API store (multipart upload) + artifact.created webhook

**Files:**
- Modify: `apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php`
- Modify: `apps/platform/routes/api.php`
- Modify: `apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php`

**Interfaces:**
- Consumes: `ArtifactController::artifact()` (Task 4), `WebhookEventRecorder`, `WebhookPayload::artifact` (Task 3).
- Produces: route `api.v1.apps.builds.artifacts.store` (POST); `ArtifactController::store(...)` returning 201 `ArtifactResource`; a private `normalizeMetadata(Request): ?array` helper.

- [ ] **Step 1: Write the failing test**

Append to `apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php` (inside the class). Add `use App\Enums\WebhookEventType;` and `use App\Models\WebhookEvent;` to the imports:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter="ArtifactApiTest::test_store"`
Expected: FAIL — POST route missing (405/404).

- [ ] **Step 3: Add the store action**

In `apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php`, add imports:

```php
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
```

Add the `store` method and the metadata helper:

```php
    public function store(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): JsonResponse
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:512000'],
            'metadata' => ['nullable'],
        ]);

        $metadata = $this->normalizeMetadata($request);

        $artifact = DB::transaction(function () use ($buildRecord, $team, $request, $data, $metadata) {
            $artifact = $buildRecord->artifacts()->create([
                'team_id' => $team->id,
                'name' => $data['name'],
                'metadata' => $metadata,
            ]);
            $artifact->addMediaFromRequest('file')->toMediaCollection('file');

            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactCreated,
                $artifact,
                WebhookPayload::artifact($artifact),
                $request->user(),
            );

            return $artifact->load('media');
        });

        return (new ArtifactResource($artifact))->response()->setStatusCode(201);
    }

    private function normalizeMetadata(Request $request): ?array
    {
        $raw = $request->input('metadata');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        abort_if(json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded), 422, 'metadata must be a JSON object.');

        return $decoded;
    }
```

- [ ] **Step 4: Register the store route**

In `apps/platform/routes/api.php`, add after the artifacts index route:

```php
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts', [ArtifactController::class, 'store'])->middleware(['abilities:artifacts:write', 'team.permission:manage_applications'])->name('apps.builds.artifacts.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactApiTest`
Expected: PASS (all artifact tests).

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php apps/platform/routes/api.php apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php
git commit -m "feat(artifacts): add API multipart upload + artifact.created webhook"
```

---

### Task 6: API destroy + artifact.deleted webhook

**Files:**
- Modify: `apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php`
- Modify: `apps/platform/routes/api.php`
- Modify: `apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php`

**Interfaces:**
- Produces: route `api.v1.apps.builds.artifacts.destroy` (DELETE); `ArtifactController::destroy(...)` returning 204.

- [ ] **Step 1: Write the failing test**

Append to `ArtifactApiTest`:

```php
    public function test_destroy_removes_artifact_and_records_deleted_event(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsWrite]);
        $artifact = $this->makeArtifact($team, $build, 'release.apk');

        $this->deleteJson($this->url($team, $app, $build).'/'.$artifact->id)->assertNoContent();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::ArtifactDeleted->value,
            'aggregate_id' => $artifact->id,
        ]);
    }

    public function test_destroy_requires_the_write_scope(): void
    {
        [$user, $team, $app, $build] = $this->context();
        $this->authenticate($user, $team, [ApiKeyScope::ArtifactsRead]);
        $artifact = $this->makeArtifact($team, $build, 'release.apk');
        $this->deleteJson($this->url($team, $app, $build).'/'.$artifact->id)->assertForbidden();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter="ArtifactApiTest::test_destroy"`
Expected: FAIL — DELETE route missing.

- [ ] **Step 3: Add the destroy action**

Add `use Illuminate\Http\Response;` to the controller imports and this method:

```php
    public function destroy(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): Response
    {
        $artifact = $this->artifact($team, $mobileApp, $buildRecord, $artifact);

        DB::transaction(function () use ($artifact, $team, $request) {
            $payload = WebhookPayload::artifact($artifact);
            $artifact->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactDeleted,
                $artifact,
                $payload,
                $request->user(),
            );
        });

        return response()->noContent();
    }
```

- [ ] **Step 4: Register the destroy route**

In `apps/platform/routes/api.php`, add:

```php
        Route::delete('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [ArtifactController::class, 'destroy'])->middleware(['abilities:artifacts:write', 'team.permission:manage_applications'])->name('apps.builds.artifacts.destroy');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=ArtifactApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/Api/V1/ArtifactController.php apps/platform/routes/api.php apps/platform/tests/Feature/Api/V1/ArtifactApiTest.php
git commit -m "feat(artifacts): add API destroy + artifact.deleted webhook"
```

---

### Task 7: Web routes + controller (store/destroy/download) with media cascade cleanup

**Files:**
- Create: `apps/platform/app/Http/Controllers/BuildArtifactController.php`
- Modify: `apps/platform/routes/web.php`
- Modify: `apps/platform/app/Models/BuildRecord.php`
- Test: `apps/platform/tests/Feature/BuildArtifactWebTest.php`

**Interfaces:**
- Consumes: `Artifact`, `WebhookEventRecorder`, `WebhookPayload::artifact`, `Team::allows()`.
- Produces: routes `accounts.apps.builds.artifacts.store` (POST), `.destroy` (DELETE), `.download` (GET). `BuildRecord` boots a `deleting` hook that deletes its artifacts through Eloquent (so Spatie purges media).

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/BuildArtifactWebTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Artifact;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildArtifactWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_and_delete_artifact(): void
    {
        Storage::fake(config('filesystems.default'));
        [$user, $team, $app, $build] = $this->context('owner');

        $this->actingAs($user)->post($this->url($team, $app, $build), [
            'name' => 'release.apk',
            'metadata' => json_encode(['git_sha' => 'abc']),
            'file' => UploadedFile::fake()->create('release.apk', 10),
        ])->assertRedirect();

        $artifact = Artifact::where('build_record_id', $build->id)->firstOrFail();
        $this->assertNotNull($artifact->getFirstMedia('file'));

        $this->actingAs($user)
            ->delete($this->url($team, $app, $build).'/'.$artifact->id)
            ->assertRedirect();
        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
    }

    public function test_viewer_cannot_upload_artifact(): void
    {
        Storage::fake(config('filesystems.default'));
        [$user, $team, $app, $build] = $this->context('viewer');

        $this->actingAs($user)->post($this->url($team, $app, $build), [
            'name' => 'release.apk',
            'file' => UploadedFile::fake()->create('release.apk', 10),
        ])->assertForbidden();
    }

    public function test_deleting_build_purges_artifact_media_record(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $team, , $build] = $this->context('owner');
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);
        $artifact->addMedia(UploadedFile::fake()->create('a.apk', 5))->toMediaCollection('file');

        $build->delete();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseMissing('media', ['model_id' => $artifact->id]);
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: BuildRecord} */
    private function context(string $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => $role]);
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

    private function url(Team $team, MobileApp $app, BuildRecord $build): string
    {
        return "/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/artifacts";
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=BuildArtifactWebTest`
Expected: FAIL — routes/controller missing.

- [ ] **Step 3: Write the web controller**

Create `apps/platform/app/Http/Controllers/BuildArtifactController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BuildArtifactController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): RedirectResponse
    {
        $this->assertCanManage($request, $team, $mobileApp, $buildRecord);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:512000'],
            'metadata' => ['nullable'],
        ]);

        DB::transaction(function () use ($buildRecord, $team, $request, $data) {
            $artifact = $buildRecord->artifacts()->create([
                'team_id' => $team->id,
                'name' => $data['name'],
                'metadata' => $this->normalizeMetadata($request),
            ]);
            $artifact->addMediaFromRequest('file')->toMediaCollection('file');

            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactCreated,
                $artifact,
                WebhookPayload::artifact($artifact),
                $request->user(),
            );
        });

        return back()->with('status', 'Artifact uploaded.');
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): RedirectResponse
    {
        $this->assertCanManage($request, $team, $mobileApp, $buildRecord);
        $artifact = $buildRecord->artifacts()->whereKey($artifact)->firstOrFail();

        DB::transaction(function () use ($artifact, $team, $request) {
            $payload = WebhookPayload::artifact($artifact);
            $artifact->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactDeleted,
                $artifact,
                $payload,
                $request->user(),
            );
        });

        return back()->with('status', 'Artifact deleted.');
    }

    public function download(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): BinaryFileResponse
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);
        $media = $buildRecord->artifacts()->whereKey($artifact)->firstOrFail()->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    private function assertBuildBelongs(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
    }

    private function assertCanManage(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);
        abort_unless($team->allows($request->user(), TeamPermission::ManageApplications), 403);
    }

    private function normalizeMetadata(Request $request): ?array
    {
        $raw = $request->input('metadata');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        abort_if(json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded), 422, 'metadata must be a JSON object.');

        return $decoded;
    }
}
```

- [ ] **Step 4: Register the web routes**

In `apps/platform/routes/web.php`, add `use App\Http\Controllers\BuildArtifactController;` at the top and, inside the `accounts.` group next to the other `apps.builds.*` routes, add:

```php
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts', [BuildArtifactController::class, 'store'])
            ->name('apps.builds.artifacts.store');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [BuildArtifactController::class, 'download'])
            ->name('apps.builds.artifacts.download');
        Route::delete('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [BuildArtifactController::class, 'destroy'])
            ->name('apps.builds.artifacts.destroy');
```

- [ ] **Step 5: Add the media cleanup hook to BuildRecord**

In `apps/platform/app/Models/BuildRecord.php`, add a `booted` method so DB cascade never orphans media:

```php
    protected static function booted(): void
    {
        static::deleting(function (BuildRecord $build): void {
            $build->artifacts()->cursor()->each(fn (Artifact $artifact) => $artifact->delete());
        });
    }
```

Add `use App\Models\Artifact;`? No — same namespace. Ensure the file references `Artifact` directly (it is in `App\Models`).

- [ ] **Step 6: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=BuildArtifactWebTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/platform/app/Http/Controllers/BuildArtifactController.php apps/platform/routes/web.php apps/platform/app/Models/BuildRecord.php apps/platform/tests/Feature/BuildArtifactWebTest.php
git commit -m "feat(artifacts): add web upload/delete/download + build media cleanup"
```

---

### Task 8: Expose artifacts to the build view

**Files:**
- Modify: `apps/platform/app/Http/Controllers/MobileAppController.php:70-197`
- Test: `apps/platform/tests/Feature/BuildArtifactWebTest.php`

**Interfaces:**
- Consumes: web routes (Task 7), `Team::allows()`.
- Produces: each `builds[]` entry in the `Apps/Show` Inertia props gains `artifacts: [{id, name, size, mime, metadata, download_url, delete_url, created_at}]`; page props gain a per-build `can_manage_artifacts` bool and `artifacts_url` (store endpoint).

- [ ] **Step 1: Write the failing test**

Append to `apps/platform/tests/Feature/BuildArtifactWebTest.php`. Add `use Inertia\Testing\AssertableInertia as Assert;` to imports:

```php
    public function test_show_exposes_artifacts_for_a_build(): void
    {
        Storage::fake(config('filesystems.default'));
        [$user, $team, $app, $build] = $this->context('owner');
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $team->id,
            'name' => 'release.apk',
            'metadata' => ['git_sha' => 'abc'],
        ]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 7))->toMediaCollection('file');

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('builds.0.can_manage_artifacts', true)
                ->where('builds.0.artifacts.0.id', $artifact->id)
                ->where('builds.0.artifacts.0.name', 'release.apk')
                ->where('builds.0.artifacts.0.metadata.git_sha', 'abc'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter="BuildArtifactWebTest::test_show_exposes_artifacts"`
Expected: FAIL — `builds.0.artifacts` missing.

- [ ] **Step 3: Eager-load artifacts and compute the permission**

In `apps/platform/app/Http/Controllers/MobileAppController.php`, in `show()`, add near the top where other permission flags are computed (alongside `$canRunPipelines`):

```php
        $canManageArtifacts = $team->allows($request->user(), \App\Enums\TeamPermission::ManageApplications);
```

Change the builds query eager-load from `->with(['ciCdRun.pipeline'])` to:

```php
                ->with(['ciCdRun.pipeline', 'artifacts.media'])
```

- [ ] **Step 4: Map artifacts into the build payload**

In the `->map(function ($build) use (...) {` closure for builds, add `$canManageArtifacts` and `$team, $mobileApp` to the `use (...)` list (they are already there for `$team, $mobileApp`; add `$canManageArtifacts`). Add this key to the returned array:

```php
                        'can_manage_artifacts' => $canManageArtifacts,
                        'artifacts_url' => route('accounts.apps.builds.artifacts.store', [$team, $mobileApp, $build]),
                        'artifacts' => $build->artifacts->map(function ($artifact) use ($team, $mobileApp, $build) {
                            $media = $artifact->getFirstMedia('file');

                            return [
                                'id' => $artifact->id,
                                'name' => $artifact->name,
                                'size' => $media?->size,
                                'mime' => $media?->mime_type,
                                'metadata' => $artifact->metadata,
                                'created_at' => $artifact->created_at,
                                'download_url' => route('accounts.apps.builds.artifacts.download', [$team, $mobileApp, $build, $artifact]),
                                'delete_url' => route('accounts.apps.builds.artifacts.destroy', [$team, $mobileApp, $build, $artifact]),
                            ];
                        })->values(),
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=BuildArtifactWebTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/MobileAppController.php apps/platform/tests/Feature/BuildArtifactWebTest.php
git commit -m "feat(artifacts): expose build artifacts in the app show view"
```

---

### Task 9: Web UI — artifacts panel in Apps/Show.svelte

**Files:**
- Modify: `apps/platform/resources/js/Pages/Apps/Show.svelte`
- Test: manual + `npm run build` (no JS test harness in repo)

**Interfaces:**
- Consumes: `builds[].artifacts`, `builds[].can_manage_artifacts`, `builds[].artifacts_url` (Task 8); `useForm` from `@inertiajs/svelte`.

- [ ] **Step 1: Read the existing build panel markup**

Run: `cd apps/platform && grep -n "expandedBuildId\|{#each builds\|logs\|manualBuildForm" resources/js/Pages/Apps/Show.svelte | head`
Identify the block rendered when a build row is expanded (`expandedBuildId === build.id`). This is where the artifacts section goes.

- [ ] **Step 2: Add the artifact upload form state**

Near the other `useForm` declarations (around the `manualBuildForm`), add:

```javascript
    const artifactForm = useForm({ name: '', metadata: '', file: null });

    const uploadArtifact = (build) => artifactForm
        .transform((data) => ({ ...data, metadata: data.metadata?.trim() ? data.metadata : null }))
        .post(build.artifacts_url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => artifactForm.reset(),
        });

    const deleteArtifact = (artifact) => router.delete(artifact.delete_url, { preserveScroll: true });

    const formatSize = (bytes) => {
        if (bytes == null) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
        return `${value.toFixed(value < 10 && unit > 0 ? 1 : 0)} ${units[unit]}`;
    };
```

Ensure `router` is imported: at the top, `import { useForm, router } from '@inertiajs/svelte';` (merge with the existing import).

- [ ] **Step 3: Render the artifacts section inside the expanded build panel**

Inside the expanded-build block, add:

```svelte
<section class="mt-4 border-t border-gray-200 pt-4">
    <h4 class="text-sm font-semibold text-gray-700">Artifacts</h4>

    {#if build.artifacts.length === 0}
        <p class="mt-2 text-sm text-gray-500">No artifacts attached to this build.</p>
    {:else}
        <ul class="mt-2 space-y-2">
            {#each build.artifacts as artifact (artifact.id)}
                <li class="flex items-center justify-between rounded border border-gray-200 px-3 py-2">
                    <div>
                        <a href={artifact.download_url} class="text-sm font-medium text-indigo-600 hover:underline">{artifact.name}</a>
                        {#if artifact.size != null}<span class="ml-2 text-xs text-gray-500">{formatSize(artifact.size)}</span>{/if}
                    </div>
                    {#if build.can_manage_artifacts}
                        <button type="button" class="text-xs text-red-600 hover:underline" on:click={() => deleteArtifact(artifact)}>Delete</button>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}

    {#if build.can_manage_artifacts}
        <form class="mt-3 space-y-2" on:submit|preventDefault={() => uploadArtifact(build)}>
            <input type="text" bind:value={artifactForm.name} placeholder="Artifact name" required class="w-full rounded border-gray-300 text-sm" />
            <textarea bind:value={artifactForm.metadata} placeholder="Metadata (JSON, optional)" rows="2" class="w-full rounded border-gray-300 text-sm"></textarea>
            <input type="file" on:change={(e) => (artifactForm.file = e.currentTarget.files[0])} required class="text-sm" />
            {#if artifactForm.errors.file}<p class="text-xs text-red-600">{artifactForm.errors.file}</p>{/if}
            {#if artifactForm.errors.name}<p class="text-xs text-red-600">{artifactForm.errors.name}</p>{/if}
            <button type="submit" disabled={artifactForm.processing} class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">Upload artifact</button>
        </form>
    {/if}
</section>
```

- [ ] **Step 4: Build the frontend to verify it compiles**

Run: `cd apps/platform && npm run build`
Expected: build succeeds with no Svelte compile errors.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/resources/js/Pages/Apps/Show.svelte
git commit -m "feat(artifacts): render artifacts list and upload form on the build panel"
```

---

### Task 10: Fastlane client multipart upload

**Files:**
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/client.rb`
- Test: `libs/fastlane-plugin/spec/client_spec.rb`

**Interfaces:**
- Produces: `Client#upload_artifact(build_id:, file_path:, name:, metadata: {})` → returns the artifact `data` Hash. `request(path, method:, body:, content_type: nil)` now accepts a raw String body with an explicit content type.

- [ ] **Step 1: Write the failing test**

Append to `libs/fastlane-plugin/spec/client_spec.rb`, inside the `RSpec.describe` block (before the final `end`):

```ruby
  describe "#upload_artifact" do
    it "posts a multipart request to the build artifacts endpoint" do
      Dir.mktmpdir do |dir|
        path = File.join(dir, "release.apk")
        File.binwrite(path, "APKBINARY")
        response = Struct.new(:body).new(JSON.generate(
          "data" => { "id" => "artifact-1", "name" => "release.apk" }
        ))

        expect(client).to receive(:request).with(
          "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-1/artifacts",
          method: :post,
          body: an_instance_of(String),
          content_type: a_string_matching(%r{\Amultipart/form-data; boundary=})
        ) do |_path, method:, body:, content_type:|
          expect(body).to include("release.apk")
          expect(body).to include("APKBINARY")
          expect(body).to include("git_sha")
          response
        end

        artifact = client.upload_artifact(
          build_id: "build-1",
          file_path: path,
          name: "release.apk",
          metadata: { "git_sha" => "abc" }
        )

        expect(artifact).to include("id" => "artifact-1", "name" => "release.apk")
      end
    end
  end
```

Ensure `require "tmpdir"` is present at the top of the spec (it already is).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/client_spec.rb -e "#upload_artifact"`
Expected: FAIL — `undefined method 'upload_artifact'`.

- [ ] **Step 3: Extend `request` to accept a raw body + content type**

In `client.rb`, change the `request` method signature and body handling:

```ruby
      def request(path, method: :get, body: nil, content_type: nil)
        uri = URI.parse(path)
        request_class = {
          get: Net::HTTP::Get,
          post: Net::HTTP::Post,
          patch: Net::HTTP::Patch
        }.fetch(method)
        request = request_class.new(uri)
        request["Authorization"] = "Bearer #{@configuration.api_key}"
        request["Accept"] = "application/json"
        request["User-Agent"] = "fastlane-plugin-app_manager/#{Fastlane::AppManager::VERSION}"
        if body
          if body.is_a?(String)
            request["Content-Type"] = content_type || "application/octet-stream"
            request.body = body
          else
            request["Content-Type"] = "application/json"
            request.body = JSON.generate(body)
          end
        end
        # ... unchanged from here (http setup, response handling) ...
```

Leave the rest of the method body (http timeouts, `response = http.request(request)`, error handling) unchanged.

- [ ] **Step 4: Add `upload_artifact` and the multipart builder**

Add the public method (near `append_build_log`):

```ruby
      def upload_artifact(build_id:, file_path:, name:, metadata: {})
        raise Error, "file_path #{file_path} does not exist" unless File.file?(file_path)

        body, content_type = multipart_body(
          fields: { "name" => name, "metadata" => JSON.generate(metadata || {}) },
          file_field: "file",
          file_path: file_path
        )
        response = request(
          app_endpoint("builds", build_id, "artifacts"),
          method: :post,
          body: body,
          content_type: content_type
        )
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned an invalid artifact response" unless parsed.is_a?(Hash) && parsed["data"].is_a?(Hash)

        parsed["data"]
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for artifact upload"
      end
```

Add the private builder (in the `private` section):

```ruby
      def multipart_body(fields:, file_field:, file_path:)
        boundary = "----AppManager#{Fastlane::AppManager::VERSION.gsub('.', '')}#{file_path.hash.abs}"
        parts = []
        fields.each do |key, value|
          parts << "--#{boundary}\r\n"
          parts << "Content-Disposition: form-data; name=\"#{key}\"\r\n\r\n"
          parts << "#{value}\r\n"
        end
        filename = File.basename(file_path)
        parts << "--#{boundary}\r\n"
        parts << "Content-Disposition: form-data; name=\"#{file_field}\"; filename=\"#{filename}\"\r\n"
        parts << "Content-Type: application/octet-stream\r\n\r\n"
        parts << File.binread(file_path)
        parts << "\r\n--#{boundary}--\r\n"

        [parts.join, "multipart/form-data; boundary=#{boundary}"]
      end
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/client_spec.rb`
Expected: PASS (all client specs).

- [ ] **Step 6: Commit**

```bash
git add libs/fastlane-plugin/lib/fastlane/plugin/app_manager/client.rb libs/fastlane-plugin/spec/client_spec.rb
git commit -m "feat(fastlane): add multipart upload_artifact to the client"
```

---

### Task 11: Fastlane helper, action, and README

**Files:**
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/helper/app_manager_helper.rb`
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_add_artifact_action.rb`
- Modify: `libs/fastlane-plugin/spec/actions_spec.rb`
- Modify: `libs/fastlane-plugin/README.md`

**Interfaces:**
- Consumes: `Helper::AppManagerHelper.resolve_build_id`, `Client#upload_artifact` (Task 10).
- Produces: `Helper::AppManagerHelper.add_artifact(**options)`; action `app_manager_add_artifact` with options `file` (required), `name`, `metadata`, `build_id`.

- [ ] **Step 1: Write the failing test**

Inspect an existing action spec block first: `cd libs/fastlane-plugin && grep -n "app_manager_create_build\|Helper::AppManagerHelper\|allow(Fastlane" spec/actions_spec.rb | head`. Then append a matching example. Add to `spec/actions_spec.rb`:

```ruby
  describe "app_manager_add_artifact" do
    it "uploads the artifact to the resolved build" do
      Dir.mktmpdir do |dir|
        path = File.join(dir, "release.apk")
        File.binwrite(path, "APK")
        allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with("build-1").and_return("build-1")
        expect(Fastlane::Helper::AppManagerHelper).to receive(:add_artifact).with(
          build_id: "build-1",
          file_path: path,
          name: "release.apk",
          metadata: { "git_sha" => "abc" }
        ).and_return("id" => "artifact-1", "name" => "release.apk")

        result = Fastlane::FastFile.new.parse(<<~FASTFILE).runner.execute(:test)
          lane :test do
            app_manager_add_artifact(
              build_id: "build-1",
              file: "#{path}",
              name: "release.apk",
              metadata: { "git_sha" => "abc" }
            )
          end
        FASTFILE

        expect(result).to include("id" => "artifact-1")
      end
    end
  end
```

Confirm `require "tmpdir"` is available in `spec/actions_spec.rb` (add `require "tmpdir"` near the top if absent).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/actions_spec.rb -e "app_manager_add_artifact"`
Expected: FAIL — action `app_manager_add_artifact` not found.

- [ ] **Step 3: Add the helper method**

In `helper/app_manager_helper.rb`, add alongside the other delegators:

```ruby
        def add_artifact(**options)
          client.upload_artifact(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end
```

- [ ] **Step 4: Add the action**

Create `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_add_artifact_action.rb`:

```ruby
module Fastlane
  module Actions
    class AppManagerAddArtifactAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        file = params[:file]
        UI.user_error!("file #{file} does not exist") unless File.file?(file.to_s)
        name = params[:name] || File.basename(file.to_s)

        artifact = Helper::AppManagerHelper.add_artifact(
          build_id: build_id,
          file_path: file,
          name: name,
          metadata: params[:metadata] || {}
        )
        UI.success("Attached artifact #{name} to App Manager build #{build_id}")
        artifact
      end

      def self.description
        "Uploads a build artifact and attaches it to an App Manager build"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(key: :file, description: "Path to the artifact file to upload", type: String),
          FastlaneCore::ConfigItem.new(key: :name, description: "Artifact name; defaults to the file basename", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :metadata, description: "JSON-compatible metadata hash", type: Hash, optional: true),
          FastlaneCore::ConfigItem.new(key: :build_id, description: "Build UUID; defaults to APP_MANAGER_BUILD_ID", type: String, optional: true)
        ]
      end

      def self.return_value
        "The artifact response returned by App Manager"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end
    end
  end
end
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd libs/fastlane-plugin && bundle exec rspec`
Expected: PASS (full suite).

- [ ] **Step 6: Document the action in the README**

In `libs/fastlane-plugin/README.md`, add an entry under the actions list describing `app_manager_add_artifact` with a short example:

```ruby
app_manager_add_artifact(
  file: "output/app-release.apk",
  name: "app-release.apk",
  metadata: { git_sha: ENV["GIT_SHA"] }
  # build_id defaults to APP_MANAGER_BUILD_ID from a prior app_manager_create_build
)
```

- [ ] **Step 7: Commit**

```bash
git add libs/fastlane-plugin/lib/fastlane/plugin/app_manager/helper/app_manager_helper.rb libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_add_artifact_action.rb libs/fastlane-plugin/spec/actions_spec.rb libs/fastlane-plugin/README.md
git commit -m "feat(fastlane): add app_manager_add_artifact action"
```

---

### Task 12: ACL regression sweep + full suite

**Files:**
- Modify: `apps/platform/tests/Feature/Api/V1/ExistingRoutesAclTest.php` (only if it enumerates all routes/scopes and now fails)
- Test: full platform + fastlane suites

**Interfaces:** none new — this task verifies the whole feature integrates.

- [ ] **Step 1: Run the ACL/scope regression tests**

Run: `cd apps/platform && php artisan test --filter="ExistingRoutesAclTest|ApiKeyScopeTest"`
Expected: PASS. If `ExistingRoutesAclTest` maintains a hardcoded route/scope list, add the three new artifact routes and the two new scopes to its expected data so it passes. Show the diff you make; do not weaken assertions.

- [ ] **Step 2: Run the full platform suite**

Run: `cd apps/platform && php artisan test`
Expected: PASS (no regressions).

- [ ] **Step 3: Run the full fastlane suite**

Run: `cd libs/fastlane-plugin && bundle exec rspec`
Expected: PASS.

- [ ] **Step 4: Commit any test-data updates**

```bash
git add apps/platform/tests/Feature/Api/V1/ExistingRoutesAclTest.php
git commit -m "test(artifacts): cover new artifact routes and scopes in ACL sweep"
```

(If no file changed in Step 1, skip this commit.)

---

## Self-Review

**Spec coverage:**
- Data model / migration / MediaLibrary → Task 1. ✓
- ACL scopes + role mapping → Task 2 (+ Task 12 sweep). ✓
- Immutable REST API (index/show/download/store/destroy) → Tasks 4–6. ✓
- Tenant isolation → Tasks 4 (test) + resolver in 4/6. ✓
- Metadata normalization (JSON string vs object) → Tasks 5 & 7 `normalizeMetadata`. ✓
- Webhook events created/deleted + payload → Tasks 3, 5, 6, 7. ✓
- Web routes + UI display (multiple artifacts per build) → Tasks 7, 8, 9. ✓
- Media cleanup on build cascade → Task 7. ✓
- Fastlane client multipart + helper + action + README → Tasks 10, 11. ✓
- Testing across API/web/fastlane/webhooks → Tasks 1–12. ✓
- Out-of-scope items (no PATCH, no artifact.updated, no presigned upload) → honored (no such tasks). ✓

**Placeholder scan:** No TBD/TODO; every code step includes concrete code; commands include expected output. ✓

**Type consistency:** `upload_artifact(build_id:, file_path:, name:, metadata:)` used identically in Tasks 10 (definition), and consumed via `add_artifact` in Task 11. `normalizeMetadata` defined in Tasks 5 & 7 with identical signature. Route names `api.v1.apps.builds.artifacts.*` and `accounts.apps.builds.artifacts.*` used consistently across resource, controllers, and view. `WebhookEventType::ArtifactCreated/ArtifactDeleted` and `ApiKeyScope::ArtifactsRead/ArtifactsWrite` consistent throughout. ✓
