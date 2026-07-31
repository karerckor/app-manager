# App Files API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the app media library (arbitrary files attached to a `MobileApp`) over the REST API v1 with its own API key scopes, webhook events, CLI commands, fastlane actions and OpenAPI documentation.

**Architecture:** A new `App\Support\Apps\AppFileLibrary` service owns the media-library rules (duplicate-name rejection, metadata handling, webhook emission). Both the existing web controllers and a new `Api\V1\AppFileController` call it, so the UI and the API cannot drift. Files are addressed by `media.file_name`; the API returns `AppFileResource` and streams bytes through the existing `MediaFileResponse` helper.

**Tech Stack:** Laravel 12 (PHP 8.3), spatie/laravel-medialibrary, Laravel Sanctum tokens with abilities, PHPUnit, Bash 3.2 CLI (`appmctl`), Ruby/RSpec fastlane plugin, OpenAPI 3 YAML.

Spec: `docs/superpowers/specs/2026-07-31-app-files-api-design.md`

## Global Constraints

- Media collection name is `files` on `MobileApp` (registered in `app/Models/MobileApp.php:55`). Never rename it.
- Upload size limit comes from `config('media-library.max_file_size_kb')` — use it in validation, never a hard-coded number.
- API routes live in the existing `Route::prefix('v1/{team:slug}')` group in `apps/platform/routes/api.php` (middleware `auth:sanctum`, `team.member`, `SetActorContext`, `scopeBindings()`, name prefix `api.v1.`).
- Every new API route carries both an `abilities:<scope>` and a `team.permission:<permission>` middleware.
- Cross-tenant access answers 404, never 403.
- Scopes are exactly `files:read` and `files:write`. Webhook events are exactly `app_file.created` and `app_file.deleted`.
- Duplicate file name on upload → HTTP 422 with message `A file named {name} is already attached.` under the `file` key.
- PHP style: run `cd apps/platform && vendor/bin/pint --dirty` before each commit.
- Platform tests run with `cd apps/platform && php artisan test`.
- CLI must stay Bash 3.2 compatible (no associative arrays, no `${var^^}`, no `readarray`).
- The distributable `libs/cli/bin/appmctl` is generated: `bash libs/cli/build.sh > libs/cli/bin/appmctl`. Never hand-edit it.
- Commit messages use Conventional Commits (`feat(api): …`, `test(api): …`, `docs(api): …`).

## File Structure

**Create**
- `apps/platform/app/Support/Apps/AppFileLibrary.php` — the only place that attaches, renames-checks, updates and detaches app files, and records their webhook events.
- `apps/platform/app/Http/Concerns/NormalizesFileMetadata.php` — turns request input (JSON string or array) into `array|null`, rejecting non-objects.
- `apps/platform/app/Http/Controllers/Api/V1/AppFileController.php` — HTTP surface for the API.
- `apps/platform/app/Http/Resources/Api/V1/AppFileResource.php` — JSON shape of a file.
- `apps/platform/tests/Unit/AppFileWebhookPayloadTest.php`
- `apps/platform/tests/Unit/AppFileLibraryTest.php`
- `apps/platform/tests/Feature/Api/V1/AppFileApiTest.php`
- `libs/cli/lib/commands/files.sh`
- `libs/cli/tests/test_files.sh`
- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_list_files_action.rb`
- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_upload_file_action.rb`
- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_download_file_action.rb`
- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_delete_file_action.rb`

**Modify**
- `apps/platform/app/Enums/WebhookEventType.php` — two new cases.
- `apps/platform/app/Integrations/Webhooks/WebhookPayload.php` — `appFile()`.
- `apps/platform/app/Integrations/Webhooks/WebhookEventFilterContext.php` — resolve the owning app for a `Media` aggregate.
- `apps/platform/app/Enums/ApiKeyScope.php` — two new scopes with label, permission and description.
- `apps/platform/app/Http/Controllers/AppFileController.php` — delegate to the service.
- `apps/platform/app/Http/Controllers/AppMediaLibraryController.php` — list through the service.
- `apps/platform/routes/api.php` — six routes.
- `apps/platform/tests/Feature/Api/V1/ApiKeyScopeTest.php` — expected scope list.
- `apps/platform/tests/Feature/AppMediaLibraryTest.php` — webhook regression for the web upload path.
- `apps/docs/public/openapi.yaml` — tag, three paths, `AppFile` schema, scope enum, event enums.
- `libs/cli/lib/parsing.sh`, `libs/cli/build.sh`, `libs/cli/bin/appmctl` (regenerated), `libs/cli/tests/fixtures/mock_curl.sh`, `libs/cli/README.md`.
- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/client.rb`, `.../helper/app_manager_helper.rb`, `.../version.rb`, `spec/client_spec.rb`, `spec/actions_spec.rb`, `README.md`.

---

### Task 1: Webhook events for app files

**Files:**
- Modify: `apps/platform/app/Enums/WebhookEventType.php:21`
- Modify: `apps/platform/app/Integrations/Webhooks/WebhookPayload.php` (end of class)
- Modify: `apps/platform/app/Integrations/Webhooks/WebhookEventFilterContext.php:29-49`
- Test: `apps/platform/tests/Unit/AppFileWebhookPayloadTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `WebhookEventType::AppFileCreated` (`'app_file.created'`), `WebhookEventType::AppFileDeleted` (`'app_file.deleted'`), `WebhookPayload::appFile(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): array` returning keys `id, mobile_app_id, name, mime_type, size, metadata, created_at`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/AppFileWebhookPayloadTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=AppFileWebhookPayloadTest`
Expected: FAIL — `app_file.created` missing from `subscribableValues()` and `Call to undefined method App\Integrations\Webhooks\WebhookPayload::appFile()`.

- [ ] **Step 3: Add the enum cases**

In `apps/platform/app/Enums/WebhookEventType.php`, directly after `case ArtifactDeleted = 'artifact.deleted';`:

```php
    case AppFileCreated = 'app_file.created';
    case AppFileDeleted = 'app_file.deleted';
```

- [ ] **Step 4: Add the payload builder**

In `apps/platform/app/Integrations/Webhooks/WebhookPayload.php`, add the import

```php
use Spatie\MediaLibrary\MediaCollections\Models\Media;
```

and this method after `artifact()`:

```php
    /** @return array<string, mixed> */
    public static function appFile(Media $media): array
    {
        return [
            'id' => $media->uuid,
            'mobile_app_id' => $media->model_id,
            'name' => $media->file_name,
            'mime_type' => $media->mime_type ?: 'application/octet-stream',
            'size' => $media->size,
            'metadata' => $media->getCustomProperty('metadata'),
            'created_at' => $media->created_at?->toISOString(),
        ];
    }
```

- [ ] **Step 5: Teach the filter context about media**

In `apps/platform/app/Integrations/Webhooks/WebhookEventFilterContext.php`, add the import

```php
use Spatie\MediaLibrary\MediaCollections\Models\Media;
```

and insert this branch after the `Artifact` branch (before the `AppIdentifier` branch):

```php
        } elseif ($aggregate instanceof Media) {
            $aggregate->loadMissing('model');

            if ($aggregate->model instanceof MobileApp) {
                $mobileApps->push($aggregate->model);
            }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=AppFileWebhookPayloadTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Verify no webhook regressions**

Run: `cd apps/platform && php artisan test --filter=Webhook`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
cd apps/platform && vendor/bin/pint --dirty
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/app/Enums/WebhookEventType.php \
  apps/platform/app/Integrations/Webhooks/WebhookPayload.php \
  apps/platform/app/Integrations/Webhooks/WebhookEventFilterContext.php \
  apps/platform/tests/Unit/AppFileWebhookPayloadTest.php
git commit -m "feat(webhooks): add app_file created and deleted events"
```

---

### Task 2: `AppFileLibrary` service and metadata normalization

**Files:**
- Create: `apps/platform/app/Http/Concerns/NormalizesFileMetadata.php`
- Create: `apps/platform/app/Support/Apps/AppFileLibrary.php`
- Test: `apps/platform/tests/Unit/AppFileLibraryTest.php`

**Interfaces:**
- Consumes: `WebhookEventType::AppFileCreated`, `WebhookEventType::AppFileDeleted`, `WebhookPayload::appFile()` from Task 1.
- Produces:
  - `AppFileLibrary::COLLECTION` (`'files'`)
  - `AppFileLibrary::query(MobileApp $app): \Illuminate\Database\Eloquent\Relations\MorphMany`
  - `AppFileLibrary::findByName(MobileApp $app, string $name): ?Media`
  - `AppFileLibrary::belongsToApp(MobileApp $app, Media $media): bool`
  - `AppFileLibrary::attach(MobileApp $app, \Illuminate\Http\UploadedFile $file, ?array $metadata = null): Media` (throws `ValidationException` on duplicate name)
  - `AppFileLibrary::updateMetadata(Media $media, ?array $metadata): Media`
  - `AppFileLibrary::detach(Media $media): void`
  - `NormalizesFileMetadata::fileMetadata(mixed $value): ?array` (protected trait method, throws `ValidationException` when the value is not a JSON object)

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/AppFileLibraryTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=AppFileLibraryTest`
Expected: FAIL — `Target class [App\Support\Apps\AppFileLibrary] does not exist.`

- [ ] **Step 3: Create the metadata trait**

Create `apps/platform/app/Http/Concerns/NormalizesFileMetadata.php`:

```php
<?php

namespace App\Http\Concerns;

use Illuminate\Validation\ValidationException;

trait NormalizesFileMetadata
{
    /**
     * Accepts a JSON object string or an already decoded associative array.
     *
     * @return array<string, mixed>|null
     */
    protected function fileMetadata(mixed $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $this->rejectMetadata();
            }

            return $value;
        }

        if (! is_string($value) || ! is_object(json_decode($value))) {
            $this->rejectMetadata();
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function rejectMetadata(): never
    {
        throw ValidationException::withMessages([
            'metadata' => 'Metadata must be a JSON object.',
        ]);
    }
}
```

- [ ] **Step 4: Create the service**

Create `apps/platform/app/Support/Apps/AppFileLibrary.php`:

```php
<?php

namespace App\Support\Apps;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppFileLibrary
{
    public const COLLECTION = 'files';

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

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

        $this->record($app, WebhookEventType::AppFileCreated, $media);

        return $media;
    }

    /** @param array<string, mixed>|null $metadata */
    public function updateMetadata(Media $media, ?array $metadata): Media
    {
        if ($metadata === null) {
            $media->forgetCustomProperty('metadata');
        } else {
            $media->setCustomProperty('metadata', $metadata);
        }

        $media->save();

        return $media;
    }

    public function detach(Media $media): void
    {
        $media->loadMissing('model');
        $app = $media->model;
        $media->delete();

        if ($app instanceof MobileApp) {
            $this->record($app, WebhookEventType::AppFileDeleted, $media);
        }
    }

    private function record(MobileApp $app, WebhookEventType $event, Media $media): void
    {
        $this->webhookEvents->record(
            $app->loadMissing('team')->team,
            $event,
            $media,
            WebhookPayload::appFile($media),
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=AppFileLibraryTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
cd apps/platform && vendor/bin/pint --dirty
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/app/Support/Apps/AppFileLibrary.php \
  apps/platform/app/Http/Concerns/NormalizesFileMetadata.php \
  apps/platform/tests/Unit/AppFileLibraryTest.php
git commit -m "feat(apps): add an app file library service"
```

---

### Task 3: Move the web controllers onto the service

**Files:**
- Modify: `apps/platform/app/Http/Controllers/AppFileController.php` (whole file)
- Modify: `apps/platform/app/Http/Controllers/AppMediaLibraryController.php:19-33`
- Test: `apps/platform/tests/Feature/AppMediaLibraryTest.php`

**Interfaces:**
- Consumes: everything `AppFileLibrary` and `NormalizesFileMetadata` produce in Task 2.
- Produces: no new interfaces. Web routes, redirects and flash messages stay byte-identical; the web upload/delete paths now emit `app_file.*` webhook events.

- [ ] **Step 1: Write the failing test**

Append to the class in `apps/platform/tests/Feature/AppMediaLibraryTest.php` (keep the file's existing imports and add `use App\Enums\WebhookEventType;` if it is missing):

```php
    public function test_web_upload_and_delete_record_app_file_webhook_events(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->post(route('accounts.apps.media.files.store', [$team, $app]), [
                'file' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect();

        $media = $app->refresh()->getMedia('files')->first();
        $this->assertNotNull($media);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppFileCreated->value,
            'aggregate_id' => $media->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.apps.media.files.destroy', [$team, $app, $media]))
            ->assertRedirect();

        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppFileDeleted->value,
            'aggregate_id' => $media->getKey(),
        ]);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=AppMediaLibraryTest`
Expected: the new test FAILS (no `webhook_events` row) while the pre-existing tests in the file PASS.

- [ ] **Step 3: Rewrite the web file controller on top of the service**

Replace the body of `apps/platform/app/Http/Controllers/AppFileController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Concerns\NormalizesFileMetadata;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppFilePreview;
use App\Support\Apps\AppFileLibrary;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFileController extends Controller
{
    use NormalizesFileMetadata;

    public function __construct(private readonly AppFileLibrary $files) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team, $mobileApp);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('media-library.max_file_size_kb')],
            'metadata' => ['nullable', 'string', 'max:65535', 'json'],
        ]);

        $this->files->attach($mobileApp, $data['file'], $this->fileMetadata($data['metadata'] ?? null));

        return back()->with('status', 'File uploaded.');
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): RedirectResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);

        $data = $request->validate([
            'metadata' => ['nullable', 'string', 'max:65535', 'json'],
        ]);

        $this->files->updateMetadata($media, $this->fileMetadata($data['metadata'] ?? null));

        return back()->with('status', 'File metadata updated.');
    }

    public function download(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $fileName,
    ): StreamedResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $media = $this->files->findByName($mobileApp, $fileName);
        abort_unless($media instanceof Media, 404);

        return MediaFileResponse::download($media, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): StreamedResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);
        abort_unless(AppFilePreview::type($media->mime_type), 404);

        return MediaFileResponse::inline($media, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Media $media,
    ): RedirectResponse {
        $this->authorizeTeam($request, $team, $mobileApp);
        $this->assertFileBelongsToApp($mobileApp, $media);
        $this->files->detach($media);

        return back()->with('status', 'File deleted.');
    }

    private function authorizeTeam(Request $request, Team $team, MobileApp $mobileApp): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function assertFileBelongsToApp(MobileApp $mobileApp, Media $media): void
    {
        abort_unless($this->files->belongsToApp($mobileApp, $media), 404);
    }
}
```

- [ ] **Step 4: Read the file list through the service**

In `apps/platform/app/Http/Controllers/AppMediaLibraryController.php`, add the import `use App\Support\Apps\AppFileLibrary;`, add a constructor above `__invoke()`:

```php
    public function __construct(private readonly AppFileLibrary $files) {}
```

and replace

```php
                'files' => $mobileApp->getMedia('files')
                    ->sortByDesc('created_at')
```

with

```php
                'files' => $this->files->query($mobileApp)
                    ->latest()
                    ->get()
```

- [ ] **Step 5: Run the media library tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter=AppMediaLibraryTest`
Expected: PASS, including the new webhook test.

- [ ] **Step 6: Run the wider web suite for regressions**

Run: `cd apps/platform && php artisan test --filter="AppMediaLibrary|MediaLibraryInstall|AppOverviewPage"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd apps/platform && vendor/bin/pint --dirty
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/app/Http/Controllers/AppFileController.php \
  apps/platform/app/Http/Controllers/AppMediaLibraryController.php \
  apps/platform/tests/Feature/AppMediaLibraryTest.php
git commit -m "refactor(apps): move the media library web routes onto AppFileLibrary"
```

---

### Task 4: `files:read` and `files:write` API key scopes

**Files:**
- Modify: `apps/platform/app/Enums/ApiKeyScope.php` (cases, `requiredPermission()`, `resourceLabel()`, `description()`)
- Test: `apps/platform/tests/Feature/Api/V1/ApiKeyScopeTest.php:20-46`

**Interfaces:**
- Consumes: nothing.
- Produces: `ApiKeyScope::FilesRead` (`'files:read'`) and `ApiKeyScope::FilesWrite` (`'files:write'`), used by the routes in Task 5.

- [ ] **Step 1: Write the failing test**

In `apps/platform/tests/Feature/Api/V1/ApiKeyScopeTest.php`, insert the two scopes into the expected list of `test_all_application_features_have_independent_read_and_write_scopes`, directly after `'assets:write',`:

```php
            'files:read',
            'files:write',
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=ApiKeyScopeTest`
Expected: FAIL — the arrays differ by the two missing `files:*` entries.

- [ ] **Step 3: Add the enum cases**

In `apps/platform/app/Enums/ApiKeyScope.php`, after `case AssetsWrite = 'assets:write';`:

```php
    case FilesRead = 'files:read';
    case FilesWrite = 'files:write';
```

- [ ] **Step 4: Map permissions, labels and descriptions**

In the same file:

- `requiredPermission()`: add `self::FilesRead` to the `TeamPermission::View` arm and `self::FilesWrite` to the `TeamPermission::ManageApplications` arm.
- `resourceLabel()`: add `'files' => 'App files',` after the `'assets'` arm.
- `description()`: add

```php
            self::FilesRead => 'View and download files attached to an application.',
            self::FilesWrite => 'Upload, update and delete files attached to an application.',
```

after the `AssetsWrite` arm.

- [ ] **Step 5: Run the scope tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter="ApiKeyScopeTest|TeamApiKey|DemoApiKey"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd apps/platform && vendor/bin/pint --dirty
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/app/Enums/ApiKeyScope.php apps/platform/tests/Feature/Api/V1/ApiKeyScopeTest.php
git commit -m "feat(api): add files read and write api key scopes"
```

---

### Task 5: API endpoints

**Files:**
- Create: `apps/platform/app/Http/Resources/Api/V1/AppFileResource.php`
- Create: `apps/platform/app/Http/Controllers/Api/V1/AppFileController.php`
- Modify: `apps/platform/routes/api.php` (imports; routes after the assets block, before the identifiers block)
- Test: `apps/platform/tests/Feature/Api/V1/AppFileApiTest.php`

**Interfaces:**
- Consumes: `AppFileLibrary` (Task 2), `NormalizesFileMetadata` (Task 2), `ApiKeyScope::FilesRead` / `FilesWrite` (Task 4).
- Produces: route names `api.v1.apps.files.index`, `api.v1.apps.files.store`, `api.v1.apps.files.show`, `api.v1.apps.files.content.show`, `api.v1.apps.files.update`, `api.v1.apps.files.destroy`; JSON body documented in Task 6.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/Api/V1/AppFileApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
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

        $this->patchJson($this->url($team, $app).'/notes.pdf', ['metadata' => null])
            ->assertOk()
            ->assertJsonPath('data.metadata', null);

        $this->assertNull($media->fresh()->getCustomProperty('metadata'));
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

        return app(AppFileLibrary::class)->attach(
            $app,
            UploadedFile::fake()->create($name, 12, 'application/pdf'),
            $metadata,
        );
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=AppFileApiTest`
Expected: FAIL — `Route [api.v1.apps.files.content.show] not defined.` / 404s for every request.

- [ ] **Step 3: Create the resource**

Create `apps/platform/app/Http/Resources/Api/V1/AppFileResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Media */
class AppFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $team = $request->route('team');
        $app = $request->route('mobileApp');

        return [
            'id' => $this->uuid,
            'name' => $this->file_name,
            'extension' => strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION)),
            'mime_type' => $this->mime_type ?: 'application/octet-stream',
            'size' => $this->size,
            'metadata' => $this->getCustomProperty('metadata'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'urls' => [
                'self' => route('api.v1.apps.files.show', [$team, $app, $this->file_name]),
                'content' => route('api.v1.apps.files.content.show', [$team, $app, $this->file_name]),
            ],
        ];
    }
}
```

- [ ] **Step 4: Create the API controller**

Create `apps/platform/app/Http/Controllers/Api/V1/AppFileController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\NormalizesFileMetadata;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppFileResource;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppFileLibrary;
use App\Support\Storage\MediaFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFileController extends Controller
{
    use NormalizesFileMetadata, ResolvesPageSize;

    public function __construct(private readonly AppFileLibrary $files) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $this->assertAppBelongs($team, $mobileApp);

        return AppFileResource::collection(
            $this->files->query($mobileApp)->latest()->paginate($this->perPage($request)),
        );
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->assertAppBelongs($team, $mobileApp);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('media-library.max_file_size_kb')],
            'metadata' => ['nullable'],
        ]);

        $media = $this->files->attach(
            $mobileApp,
            $data['file'],
            $this->fileMetadata($request->input('metadata')),
        );

        return (new AppFileResource($media))->response()->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp, string $file): AppFileResource
    {
        return new AppFileResource($this->file($team, $mobileApp, $file));
    }

    public function content(Team $team, MobileApp $mobileApp, string $file): StreamedResponse
    {
        return MediaFileResponse::download($this->file($team, $mobileApp, $file), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Team $team, MobileApp $mobileApp, string $file): AppFileResource
    {
        $media = $this->file($team, $mobileApp, $file);
        $request->validate(['metadata' => ['nullable']]);

        return new AppFileResource($this->files->updateMetadata(
            $media,
            $this->fileMetadata($request->input('metadata')),
        ));
    }

    public function destroy(Team $team, MobileApp $mobileApp, string $file): Response
    {
        $this->files->detach($this->file($team, $mobileApp, $file));

        return response()->noContent();
    }

    private function assertAppBelongs(Team $team, MobileApp $mobileApp): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function file(Team $team, MobileApp $mobileApp, string $file): Media
    {
        $this->assertAppBelongs($team, $mobileApp);
        $media = $this->files->findByName($mobileApp, $file);
        abort_unless($media instanceof Media, 404);

        return $media;
    }
}
```

- [ ] **Step 5: Register the routes**

In `apps/platform/routes/api.php`, add the import (alphabetically, after `AppAssetController`):

```php
use App\Http\Controllers\Api\V1\AppFileController;
```

and insert this block after the last asset route (`apps.assets.destroy`) and before the identifiers block:

```php
        Route::get('/apps/{mobileApp:slug}/files', [AppFileController::class, 'index'])
            ->middleware(['abilities:files:read', 'team.permission:view'])
            ->name('apps.files.index');
        Route::post('/apps/{mobileApp:slug}/files', [AppFileController::class, 'store'])
            ->middleware(['abilities:files:write', 'team.permission:manage_applications'])
            ->name('apps.files.store');
        Route::get('/apps/{mobileApp:slug}/files/{file}/content', [AppFileController::class, 'content'])
            ->middleware(['abilities:files:read', 'team.permission:view'])
            ->name('apps.files.content.show');
        Route::get('/apps/{mobileApp:slug}/files/{file}', [AppFileController::class, 'show'])
            ->middleware(['abilities:files:read', 'team.permission:view'])
            ->name('apps.files.show');
        Route::patch('/apps/{mobileApp:slug}/files/{file}', [AppFileController::class, 'update'])
            ->middleware(['abilities:files:write', 'team.permission:manage_applications'])
            ->name('apps.files.update');
        Route::delete('/apps/{mobileApp:slug}/files/{file}', [AppFileController::class, 'destroy'])
            ->middleware(['abilities:files:write', 'team.permission:manage_applications'])
            ->name('apps.files.destroy');
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=AppFileApiTest`
Expected: PASS (12 tests).

- [ ] **Step 7: Run the full platform suite**

Run: `cd apps/platform && php artisan test`
Expected: PASS. If `ExistingRoutesAclTest` fails, a route is missing an `abilities:` or `team.permission:` middleware — fix the route, do not change the test.

- [ ] **Step 8: Check production data for duplicate file names**

Run: `cd apps/platform && php artisan tinker --execute="dump(DB::table('media')->where('collection_name','files')->selectRaw('model_id, file_name, count(*) as c')->groupBy('model_id','file_name')->having('c','>',1)->get()->all());"`
Expected: an empty array. If rows come back, stop and report them — the plan needs an extra rename migration before name addressing is safe.

- [ ] **Step 9: Commit**

```bash
cd apps/platform && vendor/bin/pint --dirty
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/app/Http/Controllers/Api/V1/AppFileController.php \
  apps/platform/app/Http/Resources/Api/V1/AppFileResource.php \
  apps/platform/routes/api.php \
  apps/platform/tests/Feature/Api/V1/AppFileApiTest.php
git commit -m "feat(api): expose app files over the rest api"
```

---

### Task 6: OpenAPI reference

**Files:**
- Modify: `apps/docs/public/openapi.yaml` (root `tags`, paths after the assets block, `components.schemas`, api-key scope enum, two webhook event enums)

**Interfaces:**
- Consumes: the routes and JSON shape from Task 5, the scopes from Task 4, the events from Task 1.
- Produces: documentation only.

- [ ] **Step 1: Add the tag**

In the root `tags:` list, after the `Assets` entry:

```yaml
  - name: Files
    description: Arbitrary files attached to an application
```

- [ ] **Step 2: Add the paths**

Insert before `  /apps/{app_slug}/identifiers:`:

```yaml
  /apps/{app_slug}/files:
    parameters:
      - $ref: '#/components/parameters/AppSlug'

    get:
      operationId: listAppFiles
      summary: List app files
      description: Returns a paginated list of files attached to the application, newest first.
      tags: [Files]
      parameters:
        - $ref: '#/components/parameters/PerPageParam'
        - $ref: '#/components/parameters/PageParam'
      responses:
        '200':
          description: Paginated list of files
          content:
            application/json:
              schema:
                allOf:
                  - $ref: '#/components/schemas/PaginatedResponse'
                  - type: object
                    properties:
                      data:
                        type: array
                        items:
                          $ref: '#/components/schemas/AppFile'
      security:
        - bearerAuth: [files:read]

    post:
      operationId: uploadAppFile
      summary: Upload app file
      description: |
        Attach a file to the application. File names are unique per application:
        uploading a name that already exists returns `422`. Delete the existing file first to replace it.
        The maximum size is the instance's `MEDIA_MAX_FILE_SIZE_KB` (100 MB by default).
      tags: [Files]
      requestBody:
        required: true
        content:
          multipart/form-data:
            schema:
              type: object
              required: [file]
              properties:
                file:
                  type: string
                  format: binary
                  description: File to attach
                metadata:
                  type: string
                  description: JSON object (or form-encoded object) with arbitrary metadata
      responses:
        '201':
          description: File attached
          content:
            application/json:
              schema:
                type: object
                properties:
                  data:
                    $ref: '#/components/schemas/AppFile'
        '422':
          $ref: '#/components/responses/ValidationError'
      security:
        - bearerAuth: [files:write]

  /apps/{app_slug}/files/{file}:
    parameters:
      - $ref: '#/components/parameters/AppSlug'
      - $ref: '#/components/parameters/AppFileName'

    get:
      operationId: getAppFile
      summary: Get app file metadata
      tags: [Files]
      responses:
        '200':
          description: File metadata
          content:
            application/json:
              schema:
                type: object
                properties:
                  data:
                    $ref: '#/components/schemas/AppFile'
        '404':
          $ref: '#/components/responses/NotFound'
      security:
        - bearerAuth: [files:read]

    patch:
      operationId: updateAppFile
      summary: Update app file metadata
      description: Replaces the file's metadata object. Send `null` to clear it.
      tags: [Files]
      requestBody:
        required: false
        content:
          application/json:
            schema:
              type: object
              properties:
                metadata:
                  type: object
                  nullable: true
                  additionalProperties: true
      responses:
        '200':
          description: Metadata updated
          content:
            application/json:
              schema:
                type: object
                properties:
                  data:
                    $ref: '#/components/schemas/AppFile'
        '404':
          $ref: '#/components/responses/NotFound'
        '422':
          $ref: '#/components/responses/ValidationError'
      security:
        - bearerAuth: [files:write]

    delete:
      operationId: deleteAppFile
      summary: Delete app file
      tags: [Files]
      responses:
        '204':
          description: File deleted
        '404':
          $ref: '#/components/responses/NotFound'
      security:
        - bearerAuth: [files:write]

  /apps/{app_slug}/files/{file}/content:
    parameters:
      - $ref: '#/components/parameters/AppSlug'
      - $ref: '#/components/parameters/AppFileName'

    get:
      operationId: downloadAppFile
      summary: Download app file
      description: Streams the file as an attachment.
      tags: [Files]
      responses:
        '200':
          description: File content
          content:
            application/octet-stream:
              schema:
                type: string
                format: binary
        '404':
          $ref: '#/components/responses/NotFound'
      security:
        - bearerAuth: [files:read]
```

- [ ] **Step 3: Add the path parameter and the schema**

In `components.parameters`, next to `AppSlug`:

```yaml
    AppFileName:
      name: file
      in: path
      required: true
      schema:
        type: string
      description: File name as stored by the media library (e.g. `release-notes.pdf`)
```

In `components.schemas`, directly after the `Artifact` schema:

```yaml
    AppFile:
      type: object
      properties:
        id:
          type: string
          format: uuid
          nullable: true
          description: Media UUID; `null` for files attached before UUIDs were recorded
        name:
          type: string
          example: release-notes.pdf
        extension:
          type: string
          example: pdf
        mime_type:
          type: string
          example: application/pdf
        size:
          type: integer
          description: Size in bytes
        metadata:
          type: object
          nullable: true
          additionalProperties: true
        created_at:
          type: string
          format: date-time
        updated_at:
          type: string
          format: date-time
        urls:
          type: object
          properties:
            self:
              type: string
            content:
              type: string
```

- [ ] **Step 4: Add the scopes and the webhook events**

- In the api-key creation body scope `enum`, insert after `- assets:write`:

```yaml
                      - files:read
                      - files:write
```

- In both webhook event enum lists (the `CreateWebhookEndpoint` body and the shared event list further down), insert after the `artifact.deleted` entry, matching each list's indentation:

```yaml
              - app_file.created
              - app_file.deleted
```

```yaml
        - app_file.created
        - app_file.deleted
```

- [ ] **Step 5: Validate the YAML**

Run: `cd /Users/igor/Projects/Personal/app-manager && apps/platform/vendor/bin/yaml-lint apps/docs/public/openapi.yaml`
Expected: `All 1 YAML files contain valid syntax.`

- [ ] **Step 6: Check the documented scopes match the enum**

Run: `cd apps/platform && php artisan test --filter=ApiKeyScopeTest`
Expected: PASS (guards against a typo in the enum; the YAML list is verified by eye against `ApiKeyScope::values()`).

- [ ] **Step 7: Commit**

```bash
cd /Users/igor/Projects/Personal/app-manager
git add apps/docs/public/openapi.yaml
git commit -m "docs(api): document the app files endpoints"
```

---

### Task 7: `appmctl files` commands

**Files:**
- Create: `libs/cli/lib/commands/files.sh`
- Create: `libs/cli/tests/test_files.sh`
- Modify: `libs/cli/lib/parsing.sh:17-21` (usage), `:90` (allowed resources), `:112` (dispatch)
- Modify: `libs/cli/build.sh` (inlined dispatch case and module list)
- Modify: `libs/cli/bin/appmctl` (regenerated, never hand-edited)
- Modify: `libs/cli/tests/fixtures/mock_curl.sh` (routes for `apps/*/files*`)
- Modify: `libs/cli/README.md` (resource bullet list)

**Interfaces:**
- Consumes: the API from Task 5.
- Produces: `appmctl files list|get|upload|update|delete`, and the shell helpers `url_encode_segment` and `validate_file_name` defined in `files.sh`.

- [ ] **Step 1: Write the failing test**

Create `libs/cli/tests/test_files.sh`:

```bash
#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-files-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-files-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
trap cleanup EXIT

fail() { printf 'not ok - %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$1"; }
assert_contains() { case "$1" in *"$2"*) ;; *) fail "$3 (missing '$2')" ;; esac; }

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- files list ---
: >"$MOCK_LOG"
output=$("$CLI" --json files list 2>&1)
assert_contains "$output" 'release-notes.pdf' 'files list returns data'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/files' 'files list hits the files endpoint'
pass 'files list'

# --- files get (download) ---
: >"$MOCK_LOG"
output_file="$TEST_DIR/downloaded.pdf"
"$CLI" files get --name release-notes.pdf -o "$output_file" --force 2>&1
[ -f "$output_file" ] || fail 'files get writes to file'
assert_contains "$(cat "$output_file")" 'fake-file-content' 'files get downloads content'
assert_contains "$(cat "$MOCK_LOG")" 'files/release-notes.pdf/content' 'files get hits the content endpoint'
pass 'files get'

# --- files upload ---
: >"$MOCK_LOG"
upload_source="$TEST_DIR/notes.pdf"
printf 'pdf-bytes' >"$upload_source"
output=$("$CLI" files upload --file "$upload_source" --metadata '{"channel":"beta"}' 2>&1)
assert_contains "$output" 'release-notes.pdf' 'files upload prints the created file'
assert_contains "$(cat "$MOCK_LOG")" 'POST' 'files upload posts'
assert_contains "$(cat "$MOCK_ARG_LOG")" 'file=@' 'files upload sends a multipart file'
pass 'files upload'

# --- files update ---
: >"$MOCK_LOG"
output=$("$CLI" files update --name release-notes.pdf --metadata '{"channel":"stable"}' 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'PATCH' 'files update patches'
assert_contains "$output" 'release-notes.pdf' 'files update prints the file'
pass 'files update'

# --- files delete ---
: >"$MOCK_LOG"
output=$("$CLI" files delete --name release-notes.pdf 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'files delete deletes'
assert_contains "$output" 'deleted' 'files delete confirms'
pass 'files delete'

# --- name encoding ---
: >"$MOCK_LOG"
"$CLI" files get --name 'report (1).pdf' -o "$TEST_DIR/report.pdf" --force 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'report%20%281%29.pdf/content' 'files get percent-encodes the name'
pass 'files get encodes names'

# --- rejects path traversal ---
if "$CLI" files get --name '../secrets' -o "$TEST_DIR/x" --force >/dev/null 2>&1; then
  fail 'files get must reject names with slashes'
fi
pass 'files get rejects slashes'

# --- dry run ---
dry=$("$CLI" --dry-run files list 2>&1)
assert_contains "$dry" 'dry-run' 'files list dry-run'
pass 'files list dry-run'

printf '1..8\n'
```

Make it executable: `chmod +x libs/cli/tests/test_files.sh`

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash libs/cli/tests/test_files.sh`
Expected: FAIL — `appmctl: unknown resource: files`.

- [ ] **Step 3: Add mock routes**

In `libs/cli/tests/fixtures/mock_curl.sh`, insert before the `# --- assets (icon/splashscreen) ---` block:

```bash
  # --- files (app media library) ---
  apps/*/files/*/content)
    printf 'fake-file-content' >"$output"
    ;;
  apps/*/files/*)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"file-001","name":"release-notes.pdf","mime_type":"application/pdf","size":2048,"created_at":"2026-07-31"}}' >"$output" ;;
    esac
    ;;
  apps/*/files)
    case "$method" in
      POST) printf '{"data":{"id":"file-001","name":"release-notes.pdf","mime_type":"application/pdf","size":2048,"created_at":"2026-07-31"}}' >"$output" ;;
      *) printf '{"data":[{"id":"file-001","name":"release-notes.pdf","mime_type":"application/pdf","size":2048,"created_at":"2026-07-31"},{"id":"file-002","name":"mapping.txt","mime_type":"text/plain","size":512,"created_at":"2026-07-30"}]}' >"$output" ;;
    esac
    ;;
```

- [ ] **Step 4: Write the command module**

Create `libs/cli/lib/commands/files.sh`:

```bash
#!/usr/bin/env bash

# "files" subcommands: list, get, upload, update, delete.

files_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] files <action> [options]

Actions:
  list                List files attached to an app
  get                 Download a file
  upload              Attach a file to an app
  update              Replace a file's metadata
  delete              Remove a file

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --name NAME         File name (required for get, update, delete)
  --file FILE         File to upload (upload)
  --metadata JSON     JSON object with metadata (upload, update; "null" clears)
  --per-page N        Page size for list

Run "appmctl files <action> --help" for action-specific help.
EOF
}

# Percent-encode a single path segment. ASCII only; use "appmctl request" for
# names outside the ASCII range.
url_encode_segment() {
  local string="$1"
  local out=""
  local length=${#string}
  local i=0
  local char

  while [ "$i" -lt "$length" ]; do
    char=${string:$i:1}
    case "$char" in
      [A-Za-z0-9._~-]) out="$out$char" ;;
      *) out="$out$(printf '%%%02X' "'$char")" ;;
    esac
    i=$((i + 1))
  done

  printf '%s' "$out"
}

validate_file_name() {
  local value="$1"
  case "$value" in
    ''|.|..) die "--name must be a file name" ;;
    */*|*\\*) die "--name must not contain path separators" ;;
    *$'\n'*|*$'\r'*) die "--name must not contain line breaks" ;;
  esac
}

command_files() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _files_list "$@" ;;
    get) _files_get "$@" ;;
    upload) _files_upload "$@" ;;
    update) _files_update "$@" ;;
    delete) _files_delete "$@" ;;
    -h|--help|"") files_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown files action: $action (use list, get, upload, update, delete)" ;;
  esac
}

_files_list() {
  local app="${APP:-}"
  local per_page=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --per-page) require_value "$1" "$2"; per_page="$2"; shift 2 ;;
      --per-page=*) per_page=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files list [--app APP] [--per-page N]\n'
        return 0
        ;;
      *) die "unknown files list option: $1" ;;
    esac
  done

  APP="$app"
  require_app

  local path="apps/$APP/files"
  if [ -n "$per_page" ]; then
    case "$per_page" in
      ''|*[!0-9]*) die "--per-page must be a positive integer" ;;
    esac
    path="$path?per_page=$per_page"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "$path" || return 1
  local fmt='{"type":"table","fields":["name","mime_type","size","created_at"],"headers":["name","type","size","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_get() {
  local app="${APP:-}"
  local name=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files get [--app APP] --name NAME [-o FILE]\n'
        return 0
        ;;
      -*) die "unknown files get option: $1" ;;
      *)
        [ -z "$name" ] || die "unexpected argument: $1"
        name="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"

  local path="apps/$APP/files/$(url_encode_segment "$name")/content"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  check_output_destination
  api_get "$path" || return 1
  emit_response
}

_files_upload() {
  local app="${APP:-}"
  local file_path=""
  local metadata=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files upload [--app APP] --file FILE [--metadata JSON]\n'
        return 0
        ;;
      *) die "unknown files upload option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$file_path" ] || die "--file is required"
  [ -f "$file_path" ] || die "file does not exist: $file_path"

  local path="apps/$APP/files"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'file: %s\n' "$file_path"
    [ -n "$metadata" ] && printf 'metadata: %s\n' "$metadata"
    return 0
  fi

  require_curl
  require_jq
  ensure_tmpdir
  local url
  url=$(build_url "$path") || return 1

  reset_request
  REQUEST_BODY_KIND="multipart"
  [ -n "$metadata" ] && REQUEST_FORMS+=("metadata=$metadata")
  REQUEST_FILES+=("file=$file_path")

  perform_request POST "$url" || return 1
  local fmt='{"type":"resource","fields":{"name":"name","mime_type":"mime_type","size":"size","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_update() {
  local app="${APP:-}"
  local name=""
  local metadata=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files update [--app APP] --name NAME --metadata JSON\n'
        return 0
        ;;
      *) die "unknown files update option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"
  [ -n "$metadata" ] || die "--metadata is required"

  local path="apps/$APP/files/$(url_encode_segment "$name")"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: PATCH\n'
    printf 'url: %s\n' "$url"
    printf 'metadata: %s\n' "$metadata"
    return 0
  fi

  require_jq
  ensure_tmpdir
  local body_file
  body_file=$(new_temp_file files-update) || return 1
  printf '%s' "$metadata" | "$JQ_BIN" '{metadata: .}' >"$body_file" \
    || die "--metadata must be valid JSON"

  api_patch "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"name":"name","mime_type":"mime_type","size":"size","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_delete() {
  local app="${APP:-}"
  local name=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files delete [--app APP] --name NAME\n'
        return 0
        ;;
      -*) die "unknown files delete option: $1" ;;
      *)
        [ -z "$name" ] || die "unexpected argument: $1"
        name="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"

  local path="apps/$APP/files/$(url_encode_segment "$name")"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: DELETE\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_delete "$path" || return 1
  printf 'File %s deleted\n' "$name"
}
```

Notes on the helpers used above (all already exist in `libs/cli/lib/http.sh` and `core.sh`): `new_temp_file <label>` prints a path inside the CLI temp dir; `api_patch <path> <body_file>` sends a JSON body; `perform_request`, `reset_request`, `REQUEST_BODY_KIND`, `REQUEST_FORMS`, `REQUEST_FILES` drive multipart uploads; `emit_formatted_response`, `emit_response`, `check_output_destination` handle output. Do **not** use `create_json_string_body` — it is log-specific and always wraps the value under a `content` key.

`appmctl files update --metadata null` clears the metadata, because `jq '{metadata: .}'` maps the JSON literal `null` to `{"metadata":null}`, which the API treats as "clear".

- [ ] **Step 5: Register the resource**

In `libs/cli/lib/parsing.sh`:
- usage list (after the `artifacts` line): `  files           Manage files attached to an app`
- allowed-resource case at line 90: add `files` → `request|stream|apps|builds|logs|artifacts|files|credentials|integrations|pipelines|webhooks)`
- dispatch case: `    files) source "$cmd_dir/files.sh"; command_files "${REMAINING_ARGS[@]}" ;;`

In `libs/cli/build.sh`:
- add `\    files) command_files "${REMAINING_ARGS[@]}" ;;\` to the inlined `dispatch_command` block, after the `artifacts` line
- add `files` to the module loop: `for cmd in request stream builds logs artifacts files apps credentials integrations pipelines webhooks; do`

- [ ] **Step 6: Rebuild the distributable and run the test**

Run:
```bash
cd /Users/igor/Projects/Personal/app-manager
bash libs/cli/build.sh > /tmp/appmctl.new && mv /tmp/appmctl.new libs/cli/bin/appmctl && chmod +x libs/cli/bin/appmctl
bash -n libs/cli/bin/appmctl
bash libs/cli/tests/test_files.sh
```
Expected: `bash -n` silent, then `ok - …` for all 8 checks and `1..8`.

- [ ] **Step 7: Run the rest of the CLI suite**

Run: `for t in libs/cli/tests/test_*.sh; do bash "$t" || break; done`
Expected: every suite reports `ok -` lines with no `not ok`.

- [ ] **Step 8: Document the resource**

In `libs/cli/README.md`, add to the bullet list of resources, after the `artifacts` bullet:

```markdown
- **`files`** — list, download, upload, update, and delete files attached to an app
```

- [ ] **Step 9: Commit**

```bash
cd /Users/igor/Projects/Personal/app-manager
git add libs/cli/lib/commands/files.sh libs/cli/lib/parsing.sh libs/cli/build.sh \
  libs/cli/bin/appmctl libs/cli/tests/test_files.sh libs/cli/tests/fixtures/mock_curl.sh \
  libs/cli/README.md
git commit -m "feat(cli): add appmctl files commands"
```

---

### Task 8: fastlane actions

**Files:**
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/client.rb` (four methods next to the artifact methods)
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/helper/app_manager_helper.rb` (four wrappers)
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_list_files_action.rb`
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_upload_file_action.rb`
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_download_file_action.rb`
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_delete_file_action.rb`
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/version.rb` (0.3.0 → 0.4.0)
- Modify: `libs/fastlane-plugin/README.md` (scope table, action table)
- Test: `libs/fastlane-plugin/spec/client_spec.rb`, `libs/fastlane-plugin/spec/actions_spec.rb`

**Interfaces:**
- Consumes: the API from Task 5 and the scopes from Task 4.
- Produces:
  - `Client#list_files -> Array<Hash>`
  - `Client#upload_file(file_path:, metadata: {}) -> Hash`
  - `Client#download_file(name:, output_path:, overwrite: true) -> String`
  - `Client#delete_file(name:) -> true`
  - `Helper::AppManagerHelper.list_files/upload_file/download_file/delete_file`
  - Actions `app_manager_list_files`, `app_manager_upload_file`, `app_manager_download_file`, `app_manager_delete_file`; lane-context key `APP_MANAGER_FILES`.

- [ ] **Step 1: Write the failing client specs**

Append to `libs/fastlane-plugin/spec/client_spec.rb`, inside the top-level `RSpec.describe`:

```ruby
  describe "#list_files" do
    it "fetches files from the app files endpoint" do
      files = [{ "name" => "release-notes.pdf" }, { "name" => "mapping.txt" }]
      expect(client).to receive(:get_json).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files"
      ).and_return("data" => files)

      expect(client.list_files.length).to eq(2)
    end

    it "raises when the payload is not a list" do
      allow(client).to receive(:get_json).and_return("data" => {})

      expect { client.list_files }.to raise_error(Fastlane::AppManager::Error, /invalid files response/)
    end
  end

  describe "#upload_file" do
    it "posts a multipart request to the app files endpoint" do
      Dir.mktmpdir do |dir|
        path = File.join(dir, "release-notes.pdf")
        File.binwrite(path, "PDFBYTES")
        response = Struct.new(:body).new(JSON.generate(
          "data" => { "name" => "release-notes.pdf", "size" => 8 }
        ))

        expect(client).to receive(:request).with(
          "https://manager.example/api/v1/mobile-team/apps/customer-app/files",
          method: :post,
          body: an_instance_of(String),
          content_type: a_string_matching(%r{\Amultipart/form-data; boundary=})
        ) do |_path, method:, body:, content_type:|
          expect(body).to include("release-notes.pdf")
          expect(body).to include("PDFBYTES")
          expect(body).to include("channel")
          response
        end

        file = client.upload_file(file_path: path, metadata: { "channel" => "beta" })

        expect(file).to include("name" => "release-notes.pdf")
      end
    end
  end

  describe "#download_file" do
    it "downloads the file content to the requested path" do
      response = Struct.new(:body).new("pdf-bytes")
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files/release-notes.pdf/content"
      ).and_return(response)

      Dir.mktmpdir do |directory|
        output = File.join(directory, "release-notes.pdf")

        expect(client.download_file(name: "release-notes.pdf", output_path: output)).to eq(output)
        expect(File.binread(output)).to eq("pdf-bytes")
      end
    end
  end

  describe "#delete_file" do
    it "deletes the file by name" do
      response = Struct.new(:body).new("")
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files/release-notes.pdf",
        method: :delete,
        body: nil
      ).and_return(response)

      expect(client.delete_file(name: "release-notes.pdf")).to be(true)
    end
  end
```

- [ ] **Step 2: Run the specs to verify they fail**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/client_spec.rb`
Expected: FAIL — `undefined method 'list_files'`.

- [ ] **Step 3: Add the client methods**

In `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/client.rb`, after `download_artifact`:

```ruby
      def list_files
        body = get_json(app_endpoint("files"))
        files = body["data"]
        raise Error, "App Manager returned an invalid files response" unless files.is_a?(Array)

        files
      end

      def upload_file(file_path:, metadata: {})
        raise Error, "file_path #{file_path} does not exist" unless File.file?(file_path)

        fields = {}
        fields["metadata"] = JSON.generate(metadata) if metadata && !metadata.empty?
        body, content_type = multipart_body(
          fields: fields,
          file_field: "file",
          file_path: file_path
        )
        response = request(
          app_endpoint("files"),
          method: :post,
          body: body,
          content_type: content_type
        )
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned an invalid file response" unless parsed.is_a?(Hash) && parsed["data"].is_a?(Hash)

        parsed["data"]
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for file upload"
      end

      def download_file(name:, output_path:, overwrite: true)
        download(
          app_endpoint("files", name, "content"),
          output_path,
          overwrite: overwrite,
          mode: ASSET_FILE_MODE
        )
      end

      def delete_file(name:)
        request(app_endpoint("files", name), method: :delete, body: nil)
        true
      end
```

`app_endpoint` percent-encodes each segment, so names with spaces or parentheses are handled.

- [ ] **Step 4: Run the client specs to verify they pass**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/client_spec.rb`
Expected: PASS.

- [ ] **Step 5: Write the failing action specs**

Append to `libs/fastlane-plugin/spec/actions_spec.rb`, inside the top-level `RSpec.describe`:

```ruby
  it "lists app files and exposes them through lane context" do
    files = [{ "name" => "release-notes.pdf" }]
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_files).and_return(files)

    result = Fastlane::Actions::AppManagerListFilesAction.run({})

    expect(result).to eq(files)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_FILES]
    ).to eq(files)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_FILES] = nil
  end

  it "uploads an app file with metadata" do
    file = { "name" => "release-notes.pdf" }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:upload_file).and_return(file)

    result = Fastlane::Actions::AppManagerUploadFileAction.run(
      file_path: "/tmp/release-notes.pdf",
      metadata: { "channel" => "beta" }
    )

    expect(result).to eq(file)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:upload_file).with(
      file_path: "/tmp/release-notes.pdf",
      metadata: { "channel" => "beta" }
    )
  end

  it "downloads an app file to the requested path" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_file).and_return("/tmp/notes.pdf")

    result = Fastlane::Actions::AppManagerDownloadFileAction.run(
      name: "release-notes.pdf",
      output_path: "/tmp/notes.pdf",
      overwrite: true
    )

    expect(result).to eq("/tmp/notes.pdf")
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_file).with(
      name: "release-notes.pdf",
      output_path: "/tmp/notes.pdf",
      overwrite: true
    )
  end

  it "deletes an app file by name" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:delete_file).and_return(true)

    expect(Fastlane::Actions::AppManagerDeleteFileAction.run(name: "release-notes.pdf")).to be(true)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:delete_file).with(
      name: "release-notes.pdf"
    )
  end
```

- [ ] **Step 6: Run the action specs to verify they fail**

Run: `cd libs/fastlane-plugin && bundle exec rspec spec/actions_spec.rb`
Expected: FAIL — `uninitialized constant Fastlane::Actions::AppManagerListFilesAction`.

- [ ] **Step 7: Add the helper wrappers**

In `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/helper/app_manager_helper.rb`, next to the artifact wrappers:

```ruby
        def list_files
          client.list_files
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def upload_file(**options)
          client.upload_file(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def download_file(**options)
          client.download_file(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def delete_file(**options)
          client.delete_file(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end
```

- [ ] **Step 8: Add the list action**

Create `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_list_files_action.rb`:

```ruby
module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_FILES = :APP_MANAGER_FILES
    end

    class AppManagerListFilesAction < Action
      def self.run(_params)
        files = Helper::AppManagerHelper.list_files
        Actions.lane_context[SharedValues::APP_MANAGER_FILES] = files
        files.each do |file|
          UI.message("#{file['name']} (#{file['size']} bytes, #{file['mime_type']})")
        end
        files
      end

      def self.description
        "Lists files attached to the configured app"
      end

      def self.details
        "Fetches the app file library from App Manager and stores it in APP_MANAGER_FILES."
      end

      def self.available_options
        []
      end

      def self.output
        [["APP_MANAGER_FILES", "Array of file hashes returned by App Manager"]]
      end

      def self.return_value
        "An array of file hashes"
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

- [ ] **Step 9: Add the upload action**

Create `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_upload_file_action.rb`:

```ruby
module Fastlane
  module Actions
    class AppManagerUploadFileAction < Action
      def self.run(params)
        file_path = params[:file_path]
        UI.user_error!("file_path is required") if file_path.to_s.strip.empty?

        file = Helper::AppManagerHelper.upload_file(
          file_path: file_path,
          metadata: params[:metadata] || {}
        )
        UI.success("Attached #{file['name']} to the app")
        file
      end

      def self.description
        "Attaches a file to the configured app in App Manager"
      end

      def self.details
        "File names are unique per app. Delete the existing file first to replace it."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :file_path,
            description: "Local path of the file to attach",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :metadata,
            description: "Hash of arbitrary metadata stored with the file",
            type: Hash,
            optional: true,
            default_value: {}
          )
        ]
      end

      def self.return_value
        "The created file hash"
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

- [ ] **Step 10: Add the download and delete actions**

Create `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_download_file_action.rb`:

```ruby
module Fastlane
  module Actions
    class AppManagerDownloadFileAction < Action
      def self.run(params)
        name = params[:name]
        UI.user_error!("name is required") if name.to_s.strip.empty?

        path = Helper::AppManagerHelper.download_file(
          name: name,
          output_path: params[:output_path],
          overwrite: params[:overwrite].nil? ? true : params[:overwrite]
        )
        UI.success("Downloaded #{name} to #{path}")
        path
      end

      def self.description
        "Downloads a file attached to the configured app"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :name,
            description: "File name as stored by App Manager",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :output_path,
            description: "Local path where the file will be saved",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :overwrite,
            description: "Overwrite an existing file at output_path",
            type: Boolean,
            default_value: true
          )
        ]
      end

      def self.return_value
        "Absolute path to the downloaded file"
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

Create `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_delete_file_action.rb`:

```ruby
module Fastlane
  module Actions
    class AppManagerDeleteFileAction < Action
      def self.run(params)
        name = params[:name]
        UI.user_error!("name is required") if name.to_s.strip.empty?

        Helper::AppManagerHelper.delete_file(name: name)
        UI.success("Deleted #{name} from the app")
        true
      end

      def self.description
        "Deletes a file attached to the configured app"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :name,
            description: "File name as stored by App Manager",
            type: String
          )
        ]
      end

      def self.return_value
        "true when the file was deleted"
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

- [ ] **Step 11: Run the whole plugin suite**

Run: `cd libs/fastlane-plugin && bundle exec rspec`
Expected: PASS.

- [ ] **Step 12: Bump the version and document the actions**

- `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/version.rb`: `VERSION = "0.4.0"`.
- `libs/fastlane-plugin/README.md`, "Required API scopes" table — add:

```markdown
| `app_manager_list_files`, `app_manager_download_file` | `files:read` |
| `app_manager_upload_file`, `app_manager_delete_file` | `files:write` |
```

- `libs/fastlane-plugin/README.md`, "Actions" table — add after the `app_manager_download_artifact` row:

```markdown
| `app_manager_list_files` | List files attached to the app | iOS, Android |
| `app_manager_upload_file` | Attach a file to the app | iOS, Android |
| `app_manager_download_file` | Download a file attached to the app | iOS, Android |
| `app_manager_delete_file` | Delete a file attached to the app | iOS, Android |
```

- [ ] **Step 13: Re-run the suite and commit**

```bash
cd libs/fastlane-plugin && bundle exec rspec
cd /Users/igor/Projects/Personal/app-manager
git add libs/fastlane-plugin/lib libs/fastlane-plugin/spec libs/fastlane-plugin/README.md
git commit -m "feat(fastlane): add app file actions"
```

---

## Final verification

- [ ] `cd apps/platform && php artisan test` — full platform suite green.
- [ ] `cd apps/platform && vendor/bin/pint --test` — style clean.
- [ ] `for t in libs/cli/tests/test_*.sh; do bash "$t" || break; done` — CLI suites green.
- [ ] `cd libs/fastlane-plugin && bundle exec rspec` — plugin suite green.
- [ ] `apps/platform/vendor/bin/yaml-lint apps/docs/public/openapi.yaml` — reference valid.
- [ ] Manual smoke test against the dev server: create an API key with `files:read`/`files:write` in the UI, then

```bash
export APP_MANAGER_INSTANCE_URL=http://localhost:8000/api/v1/
export APP_MANAGER_API_KEY=<token>
export APP_MANAGER_TEAM=demo-users-team
export APP_MANAGER_APP=example-app
libs/cli/bin/appmctl files upload --file /tmp/notes.pdf --metadata '{"channel":"beta"}'
libs/cli/bin/appmctl files list
libs/cli/bin/appmctl files get --name notes.pdf -o /tmp/roundtrip.pdf --force
libs/cli/bin/appmctl files delete --name notes.pdf
```

Confirm the file appears and disappears at `http://localhost:8000/accounts/demo-users-team/apps/example-app/media`, and that `app_file.created` / `app_file.deleted` show up in the webhook delivery history for a subscribed endpoint.
