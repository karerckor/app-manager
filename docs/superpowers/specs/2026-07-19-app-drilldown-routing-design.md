# App drilldown routing — design

## Problem

The app view is a single 813-line `Apps/Show.svelte` component driven by in-page
tabs (`overview`, `builds`). `MobileAppController@show` loads the entire app —
identifiers, credentials, build history, artifacts, pipelines — in one payload.
Everything lives at one URL (`/accounts/{team}/apps/{app}`), so there is no
linkable state for a single identifier, a single build, or the builds list, and
the component/controller carry too many responsibilities to reason about.

## Goal

Split the app view into dedicated, linkable pages under the app, each loading
only its own slice of data, with a shared app header and horizontal sub-nav:

| URL | Route name | Inertia page |
| --- | --- | --- |
| `/accounts/{team}/apps/{app}` | `accounts.apps.show` | `Apps/Overview` |
| `/accounts/{team}/apps/{app}/identifiers` | `accounts.apps.identifiers.index` | `Apps/Identifiers/Index` |
| `/accounts/{team}/apps/{app}/identifiers/{identifier}` | `accounts.apps.identifiers.show` | `Apps/Identifiers/Show` |
| `/accounts/{team}/apps/{app}/builds` | `accounts.apps.builds.index` | `Apps/Builds/Index` |
| `/accounts/{team}/apps/{app}/builds/{build}` | `accounts.apps.builds.show` | `Apps/Builds/Show` |

## Decisions (from brainstorming)

- **Sub-navigation:** horizontal tabs (Overview / Identifiers / Builds) under a
  shared app header. The global left sidebar (Applications / API keys /
  Integrations) is unchanged.
- **Activity feed + stats:** synthesized on the fly from existing data
  (`buildRecords`, `artifacts`). No new events table.
- **Scope:** structure/routing refactor + light redesign. Forms and modals are
  reused essentially as-is; each page adapts to its new role (e.g. a build
  becomes a full page instead of an expandable table row).

## Backend architecture (Approach A: page controllers + presenters)

Existing mutation controllers (`AppIdentifierController`, `CredentialController`,
`ManualBuildController`, `BuildArtifactController`, `ForceBuildStatusController`,
`PipelineBuildController`, `TagsController`, `AppAssetController`) stay untouched —
they keep handling store/update/destroy/cancel/status/upload. Their routes are
unchanged.

New thin page controllers render Inertia pages:

- `AppOverviewController` (invokable) → `Apps/Overview`
- `AppIdentifierPageController@index` → `Apps/Identifiers/Index`
- `AppIdentifierPageController@show` → `Apps/Identifiers/Show`
- `AppBuildPageController@index` → `Apps/Builds/Index`
- `AppBuildPageController@show` → `Apps/Builds/Show`

The heavy data-shaping currently inline in `MobileAppController::show` is
extracted 1:1 into reusable presenters so no page duplicates it:

- `CredentialPresenter` — from the private `credentialData()` method.
- `IdentifierPresenter` — the `identifiers->map(...)` block (uses `CredentialPresenter`).
- `BuildPresenter` — the `builds->map(...)` block (status, params, logs, artifacts).
- `PipelinePresenter` — the `pipelines->map(...)` block.

A shared `AppContext` shaper builds the props every app page needs for the shared
header, sub-nav, and Run-build dropdown:

- `app`: id, name, slug, description, tags, icon/assets, and header URLs
  (update, tags, destroy).
- `pipelines`: runnable pipelines (via `PipelinePresenter`) — for the Run-build dropdown.
- `identifiers` (lightweight) + `buildProfiles` — for the Manual build modal.

Each page controller calls `AppContext` for these common props, then adds its own
slice:

- **Overview** — `stats` (counts + last build) and `activity` (synthesized feed).
- **Identifiers/Index** — identifiers list with `credentials_count`.
- **Identifiers/Show** — one identifier via `IdentifierPresenter` (services +
  build targets + credentials), plus `credentialTypes` / `buildProfiles` for the modal.
- **Builds/Index** — build history via `BuildPresenter`.
- **Builds/Show** — one build via `BuildPresenter`.

The `accounts.apps.show` route is re-pointed from `MobileAppController@show` to
`AppOverviewController`; the route name is kept so existing redirects
(`store`/`update`) and `DashboardController` links stay valid. The now-unused
`show()` method (and its private `credentialData()`, once `CredentialPresenter`
exists) is removed from `MobileAppController`.

### Activity feed synthesis

Built in `AppOverviewController` (or a small `AppActivityFeed` helper) by merging:

- build events — `created` (build.created_at) and, when terminal,
  `finished` (build.finished_at) from `buildRecords`.
- artifact events — `uploaded` (artifact.created_at) from build artifacts.

Merge, sort by timestamp desc, take the latest ~12. Each entry: `{ type, title,
timestamp, url }` where `url` links to the relevant build detail page.

### Stats (synthesized)

- Identifiers count, total builds, last build (status + time), credentials count.
  Each stat card links to its sub-page.

## Frontend architecture

```
Components/
  AppShell.svelte                 (unchanged — global sidebar)

Pages/Apps/
  AppLayout.svelte                NEW. Wraps <AppShell>; renders app header
                                  (icon, name, tags, badge), "Run build ▾"
                                  dropdown, Edit button, tabs row
                                  (Overview | Identifiers | Builds), <slot/>,
                                  and mounts the shared modals.
                                  props: app, pipelines, identifiers,
                                  buildProfiles, active
  Overview.svelte                 stat cards + activity feed + details/assets aside
  Identifiers/Index.svelte        identifiers list (rows link to detail) + Add
  Identifiers/Show.svelte         one identifier + its credentials (Services /
                                  Build targets) + credential modal
  Builds/Index.svelte             "Start a build" section + history table
                                  (rows link to detail)
  Builds/Show.svelte              one build: status header, params, logs,
                                  artifacts + upload
  lib.js                          shared helpers: parseParameters, formatBytes,
                                  formatDate, formatDateTime, formatParameters,
                                  buildStatusClass, initials, formatSize

Pages/Apps/Modals/                extracted from current Show.svelte, self-contained
  EditAppModal.svelte             (AppLayout — available everywhere)
  ManualBuildModal.svelte         (AppLayout — Run build)
  PipelineBuildModal.svelte       (AppLayout — Run build)
  IdentifierFormModal.svelte      (Index=create, Show=edit)
  CredentialFormModal.svelte      (Identifiers/Show)
  ForceStatusModal.svelte         (Builds/Show)
```

Notes:

- `AppLayout` is the single owner of the shared header/tabs/quick-launch, so
  "Run build ▾" and Edit work on every sub-page. The old `Apps/Show.svelte` is
  decomposed and removed.
- Modals become standalone components (currently ~180 lines of inline markup),
  each understandable and reusable independently.
- Shared formatting/parsing helpers move to `Pages/Apps/lib.js` to avoid copying
  between pages.
- Active-build polling lives on `Builds/Index` and `Builds/Show`
  (`router.reload({ only: ['builds'] })` / `{ only: ['build'] }`), not globally.

## Page content

**Overview** (`/apps/{app}`)
- Stat cards (synthesized): Identifiers, Builds, Last build (status + when),
  Credentials — each links to its sub-page.
- Activity feed: unified latest ~12 events (build created/finished, artifact
  uploaded) with icon, title, relative time, link to build detail.
- Aside: App details (slug, created, updated) + App assets (icons upload/download)
  move here.

**Identifiers/Index** (`/apps/{app}/identifiers`)
- List of identifiers; each row shows platform, bundle id/package, version,
  credentials count; row links to detail. "Add identifier" opens
  `IdentifierFormModal` (create).

**Identifiers/Show** (`/apps/{app}/identifiers/{identifier}`)
- Identifier header (platform, bundle id, version) + Edit/Delete.
- Credentials grouped as today (Services / Build targets); credential rows +
  `CredentialFormModal`. This is the current expandable block promoted to a page.

**Builds/Index** (`/apps/{app}/builds`)
- "Start a build" section (pipelines list + Manual build), permission-gated by
  `run_pipelines` as today.
- Build history table; rows link to the build detail page.

**Builds/Show** (`/apps/{app}/builds/{build}`)
- Status header (badge, pipeline name, reporting mode, timestamps,
  Cancel / Force status / Open run actions, permission-gated).
- Sections: Launch parameters (JSON), Logs (when present), Artifacts (list +
  upload form, gated by `manage_artifacts`).

## Out of scope

- No new events/activity table (feed is synthesized).
- No changes to mutation endpoints, webhooks, or permissions model.
- No visual redesign beyond adapting each page to its new standalone role.

## Testing

- Feature tests for each new GET page route: correct Inertia component + props,
  team authorization (403 for non-members), and 404 for cross-team/non-existent
  identifier/build (scoped bindings).
- Verify existing mutation feature tests still pass unchanged.
- Manual/Playwright smoke: navigate Overview → Identifiers → identifier detail →
  Builds → build detail via tabs and links; Run build dropdown works from any page.
