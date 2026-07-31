# App Files API — Design

Date: 2026-07-31
Status: approved

## Problem

Apps can have arbitrary files attached through the media library page
(`/accounts/{team}/apps/{app}/media`). That surface is web-only: the routes in
`routes/web.php` (`AppFileController`) authenticate through the session, answer
with Inertia redirects, and have no counterpart in `routes/api.php`. There is no
API key scope for them, no CLI command, no fastlane action, and no entry in the
OpenAPI reference.

Every other file-bearing resource — app assets, credential files, build
artifacts — is reachable over the REST API. App files are the gap: CI jobs
cannot attach a changelog, a dSYM bundle, or a mapping file to an app, and
cannot fetch one back.

## Goals

- REST API v1 CRUD for app files, with upload and download.
- Dedicated `files:read` / `files:write` API key scopes.
- `app_file.created` / `app_file.deleted` webhook events, emitted from both the
  web UI and the API.
- `appmctl files` CLI commands and fastlane actions.
- OpenAPI reference updated.

## Non-goals

- Inline preview over the API. The sandboxed `preview` route stays web-only; it
  exists to render files inside our own UI.
- Conversion/variant regeneration over the API (`media/regenerate` stays
  web-only; it applies to icon/splash assets, not attached files).
- Audit-log entries for file operations. Media operations are not audited today
  and the new webhook events cover the observability need.
- Backfilling `media.uuid` for pre-existing rows.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Resource name | `files` | Short, and unambiguous against `assets` (icon/splashscreen). |
| Scopes | `files:read`, `files:write` | Separate from `assets:*`; icons and arbitrary attachments are different permissions. |
| URL identifier | `media.file_name` | Names are unique per app (enforced on upload) and known ahead of time in CI scripts. Spatie sanitizes `/`, `\`, `#` and spaces to `-`, so a name is always a single safe path segment. |
| Duplicate upload | 422, same as the UI | Keeps name-addressing valid and keeps UI and API behavior identical. Clients delete then re-upload to replace. |
| Shared logic | `AppFileLibrary` service used by web and API | Duplicate detection, metadata normalization and webhook emission live in one place. |
| Webhook event names | `app_file.created`, `app_file.deleted` | Matches the existing `mobile_app.*` / `app_identifier.*` / `artifact.*` convention. |

## API surface

All routes join the existing group in `routes/api.php`
(`v1/{team:slug}`, middleware `auth:sanctum`, `team.member`, `SetActorContext`,
`scopeBindings`, name prefix `api.v1.`), placed next to the asset routes.

| Method | Path (after `/api/v1/{team}/apps/{app}`) | Route name | Scope | Team permission |
|---|---|---|---|---|
| GET | `/files` | `apps.files.index` | `files:read` | `view` |
| POST | `/files` | `apps.files.store` | `files:write` | `manage_applications` |
| GET | `/files/{file}/content` | `apps.files.content.show` | `files:read` | `view` |
| GET | `/files/{file}` | `apps.files.show` | `files:read` | `view` |
| PATCH | `/files/{file}` | `apps.files.update` | `files:write` | `manage_applications` |
| DELETE | `/files/{file}` | `apps.files.destroy` | `files:write` | `manage_applications` |

`{file}` is `media.file_name`. The `/content` route is registered before
`/files/{file}` so a file literally named `content` cannot shadow it. Lookup
compares with `hash_equals` against the app's `files` collection, matching
`AppFileController::fileByName`; no match is a 404.

`App\Http\Controllers\Api\V1\AppFileController` owns these actions:

- **index** — `AppFileResource::collection()` over
  `$mobileApp->media()->where('collection_name', 'files')->latest()`, paginated
  with the `ResolvesPageSize` trait (`per_page`, default 25, max 100). Standard
  `data` / `links` / `meta` envelope.
- **store** — `multipart/form-data`. Fields: `file`
  (`required|file|max:` from `config('media-library.max_file_size_kb')`) and
  optional `metadata` (JSON string or object; must decode to an object).
  Returns 201 with the resource. Duplicate name returns 422 with the message
  `A file named {name} is already attached.` under the `file` key.
- **show** — 200 with the resource.
- **content** — `MediaFileResponse::download($media, ['X-Content-Type-Options' => 'nosniff'])`,
  the same helper the web download uses, so local and S3 disks both work.
- **update** — `metadata` only. A `null`/empty value clears it. Returns 200 with
  the resource.
- **destroy** — 204.

Cross-tenant requests return 404, not 403 (`$mobileApp->team_id !== $team->id`),
matching the other API controllers.

## Resource shape

`app/Http/Resources/Api/V1/AppFileResource.php`:

```json
{
  "data": {
    "id": "d4f1c0de-0000-4000-8000-000000000000",
    "name": "release-notes.pdf",
    "extension": "pdf",
    "mime_type": "application/pdf",
    "size": 20481,
    "metadata": { "channel": "beta" },
    "created_at": "2026-07-31T10:12:03+00:00",
    "updated_at": "2026-07-31T10:12:03+00:00",
    "urls": {
      "self": "https://…/api/v1/acme/apps/customer-app/files/release-notes.pdf",
      "content": "https://…/api/v1/acme/apps/customer-app/files/release-notes.pdf/content"
    }
  }
}
```

- `id` is `media.uuid`, informational only (correlates a response with a webhook
  payload); addressing is by name. The column is nullable, so rows created
  before Spatie started filling it report `null`.
- `mime_type` falls back to `application/octet-stream` when the column is null,
  as the web page already does.
- `metadata` is `custom_properties.metadata`, `null` when unset.
- Timestamps are ISO-8601, matching the other resources.

## Shared service

`App\Support\Apps\AppFileLibrary`:

```php
final class AppFileLibrary
{
    public function __construct(private WebhookEventRecorder $webhookEvents) {}

    public function query(MobileApp $app): MorphMany;
    public function findByName(MobileApp $app, string $name): ?Media;
    public function belongsToApp(MobileApp $app, Media $media): bool;
    public function attach(MobileApp $app, UploadedFile $file, ?array $metadata = null): Media;
    public function updateMetadata(Media $media, ?array $metadata): Media;
    public function detach(Media $media): void;
}
```

- `attach()` keeps the current web sequence: add to the `files` collection,
  compare `file_name` against the app's other files, and on a duplicate delete
  the just-created record and throw `ValidationException` keyed on `file`.
  Uploading first is required because Spatie sanitizes the name, so the final
  name is not known beforehand. `ValidationException` serves both transports:
  the web path redirects back with errors, the API path renders a 422.
- `attach()` and `detach()` record the webhook events, resolving the team via
  `$app->loadMissing('team')->team`. Because both controllers go through the
  service, UI and API cannot drift.
- The service never touches `Request`; validation and response shaping stay in
  the controllers.
- `App\Http\Concerns\NormalizesFileMetadata` handles the `metadata` input for
  both controllers (JSON string or array; anything that is not a JSON object
  raises `ValidationException`). `NormalizesArtifactMetadata` is left alone — it
  aborts with 422 instead of throwing, which does not suit the web path.

## Refactor of existing code

- `AppFileController` (web) delegates to `AppFileLibrary`. Routes, redirects,
  status messages and validation rules stay as they are.
- `AppMediaLibraryController` reads its file list through `AppFileLibrary::query()`.
- No behavior change is intended; `tests/Feature/AppMediaLibraryTest.php` is the
  regression guard.

## Scopes

`App\Enums\ApiKeyScope` gains `FilesRead = 'files:read'` and
`FilesWrite = 'files:write'`, inserted directly after the `assets:*` cases so
the UI groups app-level resources together:

- `requiredPermission()`: `FilesRead => TeamPermission::View`,
  `FilesWrite => TeamPermission::ManageApplications`.
- `resourceLabel()`: `'files' => 'App files'`.
- `description()`: "View and download files attached to an application." /
  "Upload, update and delete files attached to an application."

The API key UI (`groups()`) and `DemoSeeder` (`values()`) pick the scopes up
automatically. The ordered list asserted in
`tests/Feature/Api/V1/ApiKeyScopeTest.php` must be updated.

## Webhooks

- `App\Enums\WebhookEventType`: `AppFileCreated = 'app_file.created'`,
  `AppFileDeleted = 'app_file.deleted'`, placed after the artifact cases.
- `WebhookPayload::appFile(Media $media)` returns `id` (uuid),
  `mobile_app_id`, `name`, `mime_type`, `size`, `metadata`, `created_at`.
- Emitted from `AppFileLibrary::attach()` / `detach()`, so uploads from the UI
  also fire. Subscription filters and `subscribableValues()` derive from the
  enum and need no change.

## Clients

**CLI** — `libs/cli/lib/commands/files.sh`, modeled on `artifacts.sh`:

```
appmctl files list   [--app APP] [--per-page N]
appmctl files get    [--app APP] --name NAME [-o FILE]
appmctl files upload [--app APP] --file PATH [--metadata JSON]
appmctl files update [--app APP] --name NAME [--metadata JSON]
appmctl files delete [--app APP] --name NAME
```

Honors the shared `--dry-run`, `--json`, `-o/--output`, `--force` flags;
registered in `libs/cli/bin/appmctl` and in the top-level usage text.

**fastlane** — new actions `app_manager_list_files`, `app_manager_upload_file`,
`app_manager_download_file`, `app_manager_delete_file`, built on the existing
`Client#app_endpoint`, `#multipart_body` and the download pattern from
`app_manager_download_artifact_action.rb`. Client methods plus specs in
`spec/client_spec.rb` and `spec/actions_spec.rb`, a README section, and a
version bump in `version.rb`.

**Docs** — `apps/docs/public/openapi.yaml`: the three paths, an `AppFile`
schema, `bearerAuth` scope annotations, `files:read` / `files:write` added to
the scope enum in the api-keys section, and the two new webhook events in the
event list. `apps/docs/dist/` is build output and is not edited by hand.

## Testing

- `tests/Feature/Api/V1/AppFileApiTest.php` (with `Storage::fake` on the media
  disk, as in `AppAssetFilesApiTest`): listing with pagination and newest-first
  order; upload with and without metadata; duplicate name → 422; oversized file
  → 422; `content` returns the bytes and the `nosniff` header; PATCH replaces
  and clears metadata; DELETE returns 204 and removes the media row; another
  team's app → 404; missing scope → 403; a role without `manage_applications` →
  403; webhook events recorded on create and delete.
- `tests/Unit/AppFileLibraryTest.php`: duplicate detection, metadata
  normalization, webhook payload shape.
- `tests/Feature/AppMediaLibraryTest.php`: unchanged, must still pass.
- `tests/Feature/Api/V1/ApiKeyScopeTest.php`: updated scope list.
- fastlane: rspec. CLI: no bash test harness exists in the repo, so the new
  commands are verified through `--dry-run` output.

## Risks

Name addressing assumes no duplicate `file_name` within an app's `files`
collection. The uniqueness check lives in the controller, not the database, so
older rows could in principle violate it. `findByName()` resolves to the first
match ordered by `id`, which is deterministic but may not be the row a client
means when duplicates exist. Existing data is checked during implementation; if
duplicates are found, a rename migration is added to the plan.
