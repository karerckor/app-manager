# Build Artifacts — Design

**Date:** 2026-07-19
**Status:** Approved (design), pending implementation plan

## Summary

Add build **artifacts**: named files (with optional JSON metadata) attached to a
build. A single build can hold multiple artifacts. Artifacts are **immutable**
once created — the lifecycle is create / list / show / download / delete (no
update). The feature spans the data model, dual ACL (Sanctum scope + team
permission), REST API v1 endpoints, the Inertia/Svelte web UI, webhook events
for creation and deletion, and a Fastlane action so CI lanes can attach an
artifact to a build.

## Motivation

Builds currently record status, parameters and logs but have no way to retain
their output (IPA/APK/AAB binaries, dSYMs, mapping files, reports). Artifacts
close that gap and let external systems react to new outputs via webhooks and
attach them from Fastlane lanes.

## Decisions (resolved during brainstorming)

- **Storage:** Spatie MediaLibrary, single file per artifact, on the configurable
  `FILESYSTEM_DISK` (local in dev, S3 in prod). Upload size limit **~500 MB**.
  Ordinary multipart upload (no presigned/direct-to-S3 flow).
- **Write permission:** `manage_applications` (Owner + Admin). Read/download:
  `view` (all roles). Mirrors `builds:write` / `builds:read`. No new
  `TeamPermission`.
- **Immutability:** no PATCH. Editing an artifact means delete + recreate. This
  matches the webhook events (only `created` / `deleted`).
- **Metadata:** free-form JSON object, nullable.

## Data model

New model **`Artifact`** (modeled on `Credential`):

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | `HasUuids` |
| `team_id` | uuid FK → teams | tenant scope |
| `build_record_id` | uuid FK → build_records | `cascadeOnDelete` |
| `name` | string | required, max 255 |
| `metadata` | json | nullable, cast `array` |
| `created_at` / `updated_at` | timestamps | |

- File via Spatie MediaLibrary: `addMediaCollection('file')->singleFile()`, disk
  `FILESYSTEM_DISK`.
- Relationships: `Artifact belongsTo team, buildRecord`; `BuildRecord hasMany
  artifacts`.
- Migration `create_artifacts_table`: uuid pk, `foreignUuid('build_record_id')
  ->cascadeOnDelete()`, `foreignUuid('team_id')`, `string name`, `json metadata
  nullable`, timestamps, index on `build_record_id`.
- **Media cleanup on cascade:** DB-level `cascadeOnDelete` bypasses Eloquent
  model events, so Spatie would not remove media files. When a build is deleted
  through the app, delete its artifacts via Eloquent first (e.g. a `deleting`
  hook on `BuildRecord` or explicit deletion in the delete path) so media is
  purged. No soft deletes on `Artifact`.

## ACL — scopes and roles

Add to `ApiKeyScope`:

- `ArtifactsRead = 'artifacts:read'` → `requiredPermission()` = `View`
- `ArtifactsWrite = 'artifacts:write'` → `requiredPermission()` = `ManageApplications`
- `resourceLabel()`: `'artifacts' => 'Build artifacts'`
- `description()`: read = "View and download build artifacts.", write =
  "Upload and delete build artifacts."

No new `TeamPermission`. Resulting role matrix:

| Role | Read/download | Create/delete |
|---|---|---|
| Owner | ✓ | ✓ |
| Admin | ✓ | ✓ |
| Contributor | ✓ | ✗ |
| Viewer | ✓ | ✗ |

## REST API v1

Base path:
`/api/v1/{team:slug}/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts`
(under `auth:sanctum`, `team.member`, `scopeBindings`).

| Method | Path | abilities | team.permission | Action |
|---|---|---|---|---|
| GET | `/` | `artifacts:read` | `view` | index (paginated via `ResolvesPageSize`) |
| POST | `/` | `artifacts:write` | `manage_applications` | store (multipart) |
| GET | `/{artifact}` | `artifacts:read` | `view` | show |
| GET | `/{artifact}/file` | `artifacts:read` | `view` | download |
| DELETE | `/{artifact}` | `artifacts:write` | `manage_applications` | destroy |

- New `Api/V1/ArtifactController` and `Api/V1/ArtifactResource`.
- **Resource shape:** `id`, `name`, `metadata`, `build_record_id`,
  `file: { name, mime_type, size, url }` (url → `artifacts.file.show` route),
  `created_at`.
- **store validation:** `name` required string max 255; `file` required file
  max `512000` KB (~500 MB); `metadata` nullable JSON object (accept object in
  JSON body or JSON-encoded string in multipart — controller normalizes and
  validates it decodes to an object).
- **Tenant isolation:** artifact resolved through build → app → team with
  `firstOrFail` and `abort_unless($artifact->build_record_id === $build->id, 404)`
  / `team_id` check, matching the api-parity pattern. Cross-team access → 404.
- **download:** `response()->download($media->getPath(), $media->file_name)`,
  `abort_unless($media, 404)`.

## Web UI (Inertia + Svelte)

- **Web routes** under existing `accounts.apps.builds.*` group:
  `apps/{mobileApp:slug}/builds/{buildRecord}/artifacts` — `store` (POST),
  `destroy` (DELETE), `download` (GET). Team-authorized; write path checks
  `manage_applications`.
- **`MobileAppController::show`** — extend each mapped build with:
  - `artifacts: [{ id, name, size, mime, metadata, download_url, delete_url,
    created_at }]`
  - `can_manage_artifacts` (bool) and an `artifacts_url` (store endpoint).
  Eager-load `buildRecords.artifacts.media`.
- **`Apps/Show.svelte`** — inside the expanded build panel (`expandedBuildId`):
  - a list of artifacts: name, human size, download button; delete button shown
    only when `can_manage_artifacts`.
  - an upload form (when `can_manage_artifacts`): `name`, file input, optional
    `metadata` textarea (JSON). Uses `useForm`, mirroring the credentials form.

## Webhooks

- Add `WebhookEventType`: `ArtifactCreated = 'artifact.created'`,
  `ArtifactDeleted = 'artifact.deleted'`. Both flow into `subscribableValues()`
  automatically, so they appear in the endpoint subscription UI and API.
- `WebhookPayload::artifact(Artifact)`:
  `{ id, build_record_id, mobile_app_id, name, metadata,
     file: { name, size, mime_type }, created_at }`.
- Record via `WebhookEventRecorder->record(...)`:
  - on `store` → `ArtifactCreated` (aggregate = artifact, actor = request user),
    inside the same `DB::transaction`.
  - on `destroy` → `ArtifactDeleted`, with the payload captured **before**
    deletion.
  - Both the API controller and the web controller record events.

## Fastlane plugin

- **`Client`** — add multipart upload:
  - `upload_artifact(build_id:, file_path:, name:, metadata: {})` — builds a
    `multipart/form-data` POST (custom boundary, `net/http`) to
    `apps/{app}/builds/{build}/artifacts` with fields `name`, `metadata` (JSON
    string) and file part `file`. Reuses existing auth headers/error handling.
- **`Helper`** — `add_artifact(**options)` delegating to `client.upload_artifact`.
- **New action `app_manager_add_artifact`**:
  - options: `file` (String path, required), `name` (String, optional; default
    `File.basename(file)`), `metadata` (Hash, optional), `build_id` (String,
    optional; default resolves `APP_MANAGER_BUILD_ID` via
    `Helper.resolve_build_id`).
  - `is_supported?` → true; `authors` per existing convention; returns the
    artifact response.
- Update `libs/fastlane-plugin/README.md` with the new action.

## Testing

- `tests/Feature/Api/V1/ArtifactApiTest`: index/show/store/download/destroy;
  ACL matrix (scope × permission, including 403 for missing scope and missing
  permission); tenant-isolation cross-team 404s; metadata round-trip;
  `artifact.created` / `artifact.deleted` webhook events recorded.
- `ArtifactResourceTest`: resource shape incl. file block and url.
- Update `ApiKeyScopeTest` and `ExistingRoutesAclTest` for the new scopes/routes.
- Web feature test: upload, download, delete via the accounts routes; permission
  gating for Contributor/Viewer.
- Fastlane rspec: `Client#upload_artifact` (multipart body + endpoint) and the
  `app_manager_add_artifact` action spec (build_id resolution, default name).
- Webhook test: endpoint subscribed to `artifact.created` receives a delivery;
  new events present in `subscribableValues()`.

## Out of scope / non-goals

- No artifact update/versioning (immutable; delete + recreate).
- No presigned / direct-to-S3 upload or chunked/multipart-part uploads.
- No `artifact.updated` webhook event.
- No retention/expiry policy for artifact files.
- No new `TeamPermission`.
