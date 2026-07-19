# App Drilldown Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the single 813-line `Apps/Show` page into five dedicated, linkable pages (overview, identifiers list + detail, builds list + detail) under a shared app header with horizontal sub-nav.

**Architecture:** Backend uses thin per-page Inertia controllers that share data-shaping through extracted presenter classes (`CredentialPresenter`, `IdentifierPresenter`, `BuildPresenter`, `PipelinePresenter`) and a common `AppContext` shaper. Existing mutation controllers/routes are untouched. Frontend introduces an `AppLayout` that owns the app header, Run-build dropdown, and tabs, with page components and standalone modal components.

**Tech Stack:** Laravel 11 + Inertia.js (server-side), Svelte 4 (`@inertiajs/svelte`), Tailwind, Pest/PHPUnit feature tests with `Inertia\Testing\AssertableInertia`.

## Global Constraints

- All app routes live under the existing group `Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')` in `apps/platform/routes/web.php`. New GET page routes use the same scoped bindings and param names: `{mobileApp:slug}`, `{appIdentifier}`, `{buildRecord}`.
- Route names: `accounts.apps.show` (Overview), `accounts.apps.identifiers.index`, `accounts.apps.identifiers.show`, `accounts.apps.builds.index`, `accounts.apps.builds.show`.
- Team authorization pattern (copied verbatim from existing controllers): `abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);`.
- Cross-app/cross-team resource access must 404 via scoped bindings (identifier/build not belonging to `{mobileApp}` → 404).
- No new database tables. Activity feed and stats are synthesized from `buildRecords` and `artifacts`.
- Run all backend tests from `apps/platform`: `php artisan test --filter <Name>`.
- Permission gates unchanged: `TeamPermission::RunPipelines` (pipelines/cancel/force-status), `TeamPermission::ManageApplications` (artifacts).

---

## File Structure

**Backend (create):**
- `app/Support/Apps/CredentialPresenter.php` — shape one credential (from `MobileAppController::credentialData`).
- `app/Support/Apps/IdentifierPresenter.php` — shape one identifier + its services/build_targets.
- `app/Support/Apps/BuildPresenter.php` — shape one build + artifacts.
- `app/Support/Apps/PipelinePresenter.php` — shape one runnable pipeline.
- `app/Support/Apps/AppContext.php` — shared header/nav/run-build props.
- `app/Support/Apps/AppActivityFeed.php` — synthesize activity feed entries.
- `app/Http/Controllers/AppOverviewController.php` — invokable → `Apps/Overview`.
- `app/Http/Controllers/AppIdentifierPageController.php` — `index`/`show`.
- `app/Http/Controllers/AppBuildPageController.php` — `index`/`show`.

**Backend (modify):**
- `routes/web.php` — add 4 GET routes; repoint `accounts.apps.show`.
- `app/Http/Controllers/MobileAppController.php` — remove `show()` + `credentialData()`; keep `store`/`update`/`destroy`.
- `app/Http/Controllers/ManualBuildController.php:58` — redirect to `accounts.apps.builds.index`.
- `app/Http/Controllers/Integrations/PipelineBuildController.php:43` — redirect to `accounts.apps.builds.index`.

**Backend tests (create):** `AppOverviewPageTest`, `AppIdentifierPageTest`, `AppBuildPageTest`.
**Backend tests (modify):** `PlatformDashboardTest`, `AppShowDeleteUrlsTest`, `CredentialUpdateTest`, `ForceBuildStatusTest`, `BuildParametersTest`.

**Frontend (create):**
- `resources/js/Pages/Apps/lib.js` — shared helpers.
- `resources/js/Pages/Apps/AppLayout.svelte` — header + tabs + run-build dropdown + shared modals.
- `resources/js/Pages/Apps/Overview.svelte`
- `resources/js/Pages/Apps/Identifiers/Index.svelte`
- `resources/js/Pages/Apps/Identifiers/Show.svelte`
- `resources/js/Pages/Apps/Builds/Index.svelte`
- `resources/js/Pages/Apps/Builds/Show.svelte`
- `resources/js/Pages/Apps/Modals/EditAppModal.svelte`
- `resources/js/Pages/Apps/Modals/ManualBuildModal.svelte`
- `resources/js/Pages/Apps/Modals/PipelineBuildModal.svelte`
- `resources/js/Pages/Apps/Modals/IdentifierFormModal.svelte`
- `resources/js/Pages/Apps/Modals/CredentialFormModal.svelte`
- `resources/js/Pages/Apps/Modals/ForceStatusModal.svelte`

**Frontend (delete):** `resources/js/Pages/Apps/Show.svelte` (final task).

> **Frontend note:** This repo has no JS/component test suite; frontend verification is a manual/Playwright smoke step. Backend feature tests (server-side props) are the TDD driver. After Task 6 repoints `accounts.apps.show`, the browser UI is temporarily broken until Phase C lands — acceptable on this feature branch; the full PHP test suite stays green at every task boundary.

---

## Phase A — Presenters (behavior-preserving refactor)

### Task 1: Extract presenters + AppContext from MobileAppController::show

**Files:**
- Create: `app/Support/Apps/CredentialPresenter.php`, `IdentifierPresenter.php`, `BuildPresenter.php`, `PipelinePresenter.php`, `AppContext.php`
- Modify: `app/Http/Controllers/MobileAppController.php`
- Test: existing `PlatformDashboardTest`, `AppShowDeleteUrlsTest`, `CredentialUpdateTest`, `ForceBuildStatusTest`, `BuildParametersTest` (must stay green unchanged)

**Interfaces:**
- Produces:
  - `CredentialPresenter::present(Team $team, Credential $credential, AppIdentifier $identifier, ?Profile $profile): array` — identical shape to current `credentialData()`.
  - `IdentifierPresenter::present(Team $team, MobileApp $app, AppIdentifier $identifier): array` — identical shape to current `identifiers->map(...)` element (id, name, platform, identifier, version, version_code, update_url, delete_url, credentials_count, services, build_targets).
  - `BuildPresenter::present(Team $team, MobileApp $app, BuildRecord $build, bool $canRunPipelines, bool $canManageArtifacts): array` — identical shape to current `builds->map(...)` element.
  - `PipelinePresenter::present(Team $team, CiCdPipeline $pipeline): array` — identical shape to current `pipelines->map(...)` element.
  - `AppContext::for(Request $request, Team $team, MobileApp $app): array` — returns `['app' => [...header summary...], 'pipelines' => Collection, 'identifiers' => Collection<lightweight>, 'buildProfiles' => array]` where:
    - `app` = id, name, slug, description, tags, created_at, updated_at, assets (app_icon/splash_icon as today), urls (index, update, tags, destroy, identifiers, credentials, assets, manual_builds), plus nav urls: overview=`route('accounts.apps.show', ...)`, identifiers=`route('accounts.apps.identifiers.index', ...)`, builds=`route('accounts.apps.builds.index', ...)`.
    - `pipelines` = runnable pipelines via `PipelinePresenter` (empty collection when `!canRunPipelines`).
    - `identifiers` (lightweight) = each identifier `{ id, platform, identifier, name }` ordered by platform, name (for the Manual build dropdown).
    - `buildProfiles` = `BuildProfileSchema::definitions()`.

- [ ] **Step 1: Create the four presenters by lifting existing code verbatim**

`CredentialPresenter::present` body = the exact body of `MobileAppController::credentialData()` (lines 280-313), unchanged, as a `public static function`.

`IdentifierPresenter::present` body = the exact `identifiers` mapping closure (lines 116-153), replacing the inline `$this->credentialData(...)` calls with `CredentialPresenter::present(...)`, and replacing `BuildProfileSchema::definition(...)` usage as-is (keep imports).

`BuildPresenter::present` body = the exact `builds->map(...)` closure body (lines 181-226), with `$canRunPipelines`/`$canManageArtifacts` as parameters instead of captured vars.

`PipelinePresenter::present` body = the exact `pipelines->...->map(...)` element closure (lines 162-174). The `->where('enabled', true)->filter(...canDispatch())` query stays in the caller.

Each file namespace `App\Support\Apps;` with the imports currently used at the top of `MobileAppController` (Enums, Models, Support\Credentials\*, route()).

- [ ] **Step 2: Create `AppContext::for`**

```php
<?php

namespace App\Support\Apps;

use App\Enums\TeamPermission;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use Illuminate\Http\Request;

class AppContext
{
    public static function for(Request $request, Team $team, MobileApp $app): array
    {
        $canRunPipelines = $team->allows($request->user(), TeamPermission::RunPipelines);

        $assets = collect(['app_icon', 'splash_icon'])->mapWithKeys(function ($collection) use ($team, $app) {
            $media = $app->getFirstMedia($collection);

            return [$collection => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'preview_url' => route('accounts.apps.assets.preview', [$team, $app, $collection]),
                'download_url' => route('accounts.apps.assets.download', [$team, $app, $collection]),
            ] : null];
        });

        return [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'tags' => $app->tags ?? [],
                'created_at' => $app->created_at,
                'updated_at' => $app->updated_at,
                'assets' => $assets,
                'urls' => [
                    'index' => route('accounts.apps.index', [$team]),
                    'overview' => route('accounts.apps.show', [$team, $app]),
                    'identifiers_page' => route('accounts.apps.identifiers.index', [$team, $app]),
                    'builds_page' => route('accounts.apps.builds.index', [$team, $app]),
                    'update' => route('accounts.apps.update', [$team, $app]),
                    'tags' => route('accounts.apps.tags.update', [$team, $app]),
                    'destroy' => route('accounts.apps.destroy', [$team, $app]),
                    'identifiers' => route('accounts.apps.identifiers.store', [$team, $app]),
                    'credentials' => route('accounts.apps.credentials.store', [$team, $app]),
                    'assets' => route('accounts.apps.assets.upload', [$team, $app, '__collection__']),
                    'manual_builds' => route('accounts.apps.builds.store', [$team, $app]),
                ],
            ],
            'pipelines' => $canRunPipelines ? $app->ciCdPipelines()
                ->with('integration')
                ->where('enabled', true)
                ->latest()
                ->get()
                ->filter(fn ($pipeline) => $pipeline->integration->status->canDispatch())
                ->map(fn ($pipeline) => PipelinePresenter::present($team, $pipeline))
                ->values() : collect(),
            'identifiers' => $app->appIdentifiers()
                ->orderBy('platform')->orderBy('name')->get()
                ->map(fn ($identifier) => [
                    'id' => $identifier->id,
                    'platform' => $identifier->platform->value,
                    'identifier' => $identifier->identifier,
                    'name' => $identifier->name,
                ])->values(),
            'buildProfiles' => BuildProfileSchema::definitions(),
        ];
    }
}
```

- [ ] **Step 3: Refactor `MobileAppController::show` to use the presenters**

Rewrite `show()` to build its payload from `AppContext::for()` plus `IdentifierPresenter`, `BuildPresenter`. It must still render `Apps/Show` with the **same top-level keys** it does today (`app`, `identifiers`, `credentialTypes`, `buildProfiles`, `pipelines`, `builds`) so existing tests stay green. Concretely: keep the current `->load([...])` eager loads; map identifiers via `IdentifierPresenter::present($team,$mobileApp,$id)`; builds via `BuildPresenter::present(...)`; `pipelines`/`buildProfiles`/`app` from the same logic (you may inline from AppContext or call it — but note the current `app` payload does NOT include the new nav urls; adding extra url keys is harmless to existing tests). `credentialData()` stays for now only if still referenced; otherwise leave `show()` calling presenters. Do not change `store`/`update`/`destroy`.

- [ ] **Step 4: Run the full suite to verify no behavior change**

Run: `php artisan test`
Expected: PASS (same count as before; `PlatformDashboardTest`, `AppShowDeleteUrlsTest`, `CredentialUpdateTest`, `ForceBuildStatusTest`, `BuildParametersTest` all green).

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Support/Apps apps/platform/app/Http/Controllers/MobileAppController.php
git commit -m "refactor(apps): extract app page presenters and AppContext"
```

---

## Phase B — New page endpoints + cutover

### Task 2: Identifiers index page endpoint

**Files:**
- Create: `app/Http/Controllers/AppIdentifierPageController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AppIdentifierPageTest.php`

**Interfaces:**
- Consumes: `AppContext::for`.
- Produces: `AppIdentifierPageController@index` renders `Apps/Identifiers/Index` with `{ ...AppContext, identifiers: [{id,name,platform,identifier,version,version_code,credentials_count,show_url,update_url,delete_url}], credentialTypes, active:'identifiers' }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppIdentifierPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_index_lists_app_identifiers(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
            'version' => '1.0.0',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Index')
                ->where('app.id', $app->id)
                ->has('identifiers', 1)
                ->where('identifiers.0.identifier', 'com.example.atlas')
                ->where('identifiers.0.show_url', route('accounts.apps.identifiers.show', [$team, $app, $identifier])));
    }

    public function test_index_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.apps.identifiers.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter AppIdentifierPageTest`
Expected: FAIL — route `accounts.apps.identifiers.index` not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `accounts.` group, after the existing identifier mutation routes (near line 107), add:

```php
Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierPageController::class, 'index'])->name('apps.identifiers.index');
Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierPageController::class, 'show'])->name('apps.identifiers.show');
```

Add `use App\Http\Controllers\AppIdentifierPageController;` to the imports.

- [ ] **Step 4: Implement `index`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\IdentifierPresenter;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppIdentifierPageController extends Controller
{
    public function index(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);

        $mobileApp->load([
            'appIdentifiers' => fn ($q) => $q
                ->withCount(['credentials', 'profiles'])
                ->orderBy('platform')->orderBy('name'),
        ]);

        return Inertia::render('Apps/Identifiers/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'credentialTypes' => CredentialSchema::definitions(),
            'identifiers' => $mobileApp->appIdentifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'name' => $identifier->name,
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
                'version' => $identifier->version,
                'version_code' => $identifier->version_code,
                'credentials_count' => $identifier->credentials_count,
                'show_url' => route('accounts.apps.identifiers.show', [$team, $mobileApp, $identifier]),
                'update_url' => route('accounts.apps.identifiers.update', [$team, $mobileApp, $identifier]),
                'delete_url' => route('accounts.apps.identifiers.destroy', [$team, $mobileApp, $identifier]),
            ])->values(),
        ]);
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
```

> Note: `credentials_count` here counts only directly-attached service credentials. The full profile-inclusive count is computed on the detail page via `IdentifierPresenter`. If the list must match the header count exactly, use `IdentifierPresenter::present(...)['credentials_count']` instead (heavier). Keep the lightweight count for the list.

- [ ] **Step 5: Run to verify index test passes**

Run: `php artisan test --filter AppIdentifierPageTest::test_index_lists_app_identifiers`
Expected: PASS. (The `show` route exists but is unimplemented — added in Task 3.)

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/AppIdentifierPageController.php apps/platform/routes/web.php apps/platform/tests/Feature/AppIdentifierPageTest.php
git commit -m "feat(apps): add identifiers index page endpoint"
```

### Task 3: Identifier detail page endpoint

**Files:**
- Modify: `app/Http/Controllers/AppIdentifierPageController.php` (add `show`)
- Test: `tests/Feature/AppIdentifierPageTest.php` (add cases)

**Interfaces:**
- Consumes: `AppContext::for`, `IdentifierPresenter::present`.
- Produces: `AppIdentifierPageController@show` renders `Apps/Identifiers/Show` with `{ ...AppContext, identifier: IdentifierPresenter, credentialTypes, buildProfiles, active:'identifiers' }`.

- [ ] **Step 1: Add the failing tests**

```php
    public function test_show_returns_identifier_with_credentials(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Show')
                ->where('identifier.id', $identifier->id)
                ->where('identifier.identifier', 'com.example.atlas')
                ->has('identifier.services')
                ->has('identifier.build_targets')
                ->has('credentialTypes'));
    }

    public function test_show_404_when_identifier_belongs_to_another_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $otherApp->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.other',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter AppIdentifierPageTest::test_show_returns_identifier_with_credentials`
Expected: FAIL — `show` method missing / null render.

- [ ] **Step 3: Implement `show`**

```php
    public function show(Request $request, Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): Response
    {
        $this->authorizeTeam($request, $team);

        $appIdentifier->load(['credentials.media', 'profiles.credentials.media']);

        return Inertia::render('Apps/Identifiers/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'credentialTypes' => CredentialSchema::definitions(),
            'buildProfiles' => \App\Support\Credentials\BuildProfileSchema::definitions(),
            'identifier' => IdentifierPresenter::present($team, $mobileApp, $appIdentifier),
        ]);
    }
```

- [ ] **Step 4: Run to verify both new tests pass**

Run: `php artisan test --filter AppIdentifierPageTest`
Expected: PASS (all four cases).

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Http/Controllers/AppIdentifierPageController.php apps/platform/tests/Feature/AppIdentifierPageTest.php
git commit -m "feat(apps): add identifier detail page endpoint"
```

### Task 4: Builds index page endpoint

**Files:**
- Create: `app/Http/Controllers/AppBuildPageController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AppBuildPageTest.php`

**Interfaces:**
- Consumes: `AppContext::for`, `BuildPresenter::present`.
- Produces: `AppBuildPageController@index` renders `Apps/Builds/Index` with `{ ...AppContext, builds: BuildPresenter[], active:'builds' }`. `@show` (Task 5) renders `Apps/Builds/Show` with `{ ...AppContext, build: BuildPresenter, active:'builds' }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\ProfileType;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppBuildPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_index_lists_builds(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = $app->buildRecords()->create([
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Builds/Index')
                ->where('app.id', $app->id)
                ->has('builds', 1)
                ->where('builds.0.id', $build->id)
                ->where('builds.0.status', 'completed'));
    }

    public function test_show_returns_single_build(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = $app->buildRecords()->create([
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
            'parameters' => ['channel' => 'internal'],
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.show', [$team, $app, $build]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Builds/Show')
                ->where('build.id', $build->id)
                ->where('build.parameters', ['channel' => 'internal']));
    }

    public function test_show_404_when_build_belongs_to_another_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = $otherApp->buildRecords()->create([
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.show', [$team, $app, $build]))
            ->assertNotFound();
    }
}
```

> Verify `BuildRecord` required columns against `database/factories`/migrations before running; adjust the `create([...])` payload if the model requires more non-nullable fields. Use `BuildRecord::factory()` if one exists.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter AppBuildPageTest::test_index_lists_builds`
Expected: FAIL — route `accounts.apps.builds.index` not defined.

- [ ] **Step 3: Add routes**

In `routes/web.php`, after the build mutation routes (near line 96), add:

```php
Route::get('/apps/{mobileApp:slug}/builds', [AppBuildPageController::class, 'index'])->name('apps.builds.index');
Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}', [AppBuildPageController::class, 'show'])->name('apps.builds.show');
```

Add `use App\Http\Controllers\AppBuildPageController;`.

- [ ] **Step 4: Implement the controller (index + show)**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\BuildPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppBuildPageController extends Controller
{
    public function index(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);

        $builds = $mobileApp->buildRecords()
            ->with(['ciCdRun.pipeline', 'artifacts.media'])
            ->latest()->limit(30)->get()
            ->map(fn ($build) => BuildPresenter::present($team, $mobileApp, $build, $canRun, $canManage));

        return Inertia::render('Apps/Builds/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'builds' => $builds,
        ]);
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);
        $buildRecord->load(['ciCdRun.pipeline', 'artifacts.media']);

        return Inertia::render('Apps/Builds/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'build' => BuildPresenter::present($team, $mobileApp, $buildRecord, $canRun, $canManage),
        ]);
    }

    private function permissions(Request $request, Team $team): array
    {
        return [
            $team->allows($request->user(), TeamPermission::RunPipelines),
            $team->allows($request->user(), TeamPermission::ManageApplications),
        ];
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
```

- [ ] **Step 5: Run to verify all AppBuildPageTest cases pass**

Run: `php artisan test --filter AppBuildPageTest`
Expected: PASS (index, show, 404).

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/AppBuildPageController.php apps/platform/routes/web.php apps/platform/tests/Feature/AppBuildPageTest.php
git commit -m "feat(apps): add builds index and detail page endpoints"
```

### Task 5: Overview endpoint + cutover of `accounts.apps.show`

**Files:**
- Create: `app/Http/Controllers/AppOverviewController.php`, `app/Support/Apps/AppActivityFeed.php`
- Modify: `routes/web.php` (repoint `apps.show`), `app/Http/Controllers/MobileAppController.php` (remove `show`+`credentialData`), `ManualBuildController.php:58`, `Integrations/PipelineBuildController.php:43`
- Test: create `tests/Feature/AppOverviewPageTest.php`; modify `PlatformDashboardTest`, `AppShowDeleteUrlsTest`, `CredentialUpdateTest`, `ForceBuildStatusTest`, `BuildParametersTest`

**Interfaces:**
- Consumes: `AppContext::for`, `AppActivityFeed::for`.
- Produces: `AppOverviewController` (invokable) renders `Apps/Overview` with `{ ...AppContext, active:'overview', stats:{identifiers,builds,credentials,last_build:{status,at}|null}, activity:[{type,title,timestamp,url}] }`.

- [ ] **Step 1: Write the Overview test**

```php
<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_overview_renders_stats_and_activity(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->appIdentifiers()->create(['platform' => Platform::Ios, 'identifier' => 'com.example.atlas']);
        $build = $app->buildRecords()->create([
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Overview')
                ->where('app.id', $app->id)
                ->where('stats.identifiers', 1)
                ->where('stats.builds', 1)
                ->where('stats.last_build.status', 'completed')
                ->has('activity'));
    }

    public function test_overview_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->get(route('accounts.apps.show', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter AppOverviewPageTest`
Expected: FAIL — `accounts.apps.show` still renders `Apps/Show`.

- [ ] **Step 3: Implement `AppActivityFeed`**

```php
<?php

namespace App\Support\Apps;

use App\Models\MobileApp;
use App\Models\Team;

class AppActivityFeed
{
    public static function for(Team $team, MobileApp $app, int $limit = 12): array
    {
        $events = [];

        $builds = $app->buildRecords()->with('artifacts')->latest()->limit(30)->get();

        foreach ($builds as $build) {
            $url = route('accounts.apps.builds.show', [$team, $app, $build]);
            $events[] = [
                'type' => 'build_created',
                'title' => 'Build #'.$build->id.' created',
                'timestamp' => $build->created_at,
                'url' => $url,
            ];
            if ($build->finished_at !== null) {
                $events[] = [
                    'type' => 'build_'.$build->status->value,
                    'title' => 'Build #'.$build->id.' '.str_replace('_', ' ', $build->status->value),
                    'timestamp' => $build->finished_at,
                    'url' => $url,
                ];
            }
            foreach ($build->artifacts as $artifact) {
                $events[] = [
                    'type' => 'artifact_uploaded',
                    'title' => 'Artifact '.$artifact->name.' uploaded',
                    'timestamp' => $artifact->created_at,
                    'url' => $url,
                ];
            }
        }

        usort($events, fn ($a, $b) => ($b['timestamp'] <=> $a['timestamp']));

        return array_slice($events, 0, $limit);
    }
}
```

- [ ] **Step 4: Implement `AppOverviewController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppActivityFeed;
use App\Support\Apps\AppContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppOverviewController extends Controller
{
    public function __invoke(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $lastBuild = $mobileApp->buildRecords()->latest()->first();

        return Inertia::render('Apps/Overview', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'overview',
            'stats' => [
                'identifiers' => $mobileApp->appIdentifiers()->count(),
                'builds' => $mobileApp->buildRecords()->count(),
                'credentials' => $team->credentials()->count(),
                'last_build' => $lastBuild ? [
                    'status' => $lastBuild->status->value,
                    'at' => $lastBuild->finished_at ?? $lastBuild->created_at,
                ] : null,
            ],
            'activity' => AppActivityFeed::for($team, $mobileApp),
        ]);
    }
}
```

- [ ] **Step 5: Repoint the route and remove the old controller method**

In `routes/web.php` change line 82 from `[MobileAppController::class, 'show']` to `AppOverviewController::class` (invokable), keeping `->name('apps.show')`. Add `use App\Http\Controllers\AppOverviewController;`.

In `MobileAppController.php`, delete the `show()` method (lines 73-229) and the private `credentialData()` method (lines 280-313), plus now-unused imports (`AppIdentifier`, `Credential`, `Profile`, `BuildProfileSchema`, `CredentialSchema`, `BuildReportingMode`, `TeamPermission`, `Inertia`, `Response`) — remove only those no longer referenced by `store`/`update`/`destroy`.

- [ ] **Step 6: Update post-build redirects**

`ManualBuildController.php:58` and `Integrations/PipelineBuildController.php:43`: change `->route('accounts.apps.show', [...])` to `->route('accounts.apps.builds.index', [...])` (same route params: `[$team, $mobileApp]` / `[$team, $pipeline->mobileApp]`).

- [ ] **Step 7: Migrate the affected existing tests to the new pages**

Update these assertions (payload moved off `apps.show`):

- `PlatformDashboardTest`:
  - `test_user_can_add_an_identifier_and_view_the_app_drilldown` — point `get()` at `route('accounts.apps.identifiers.index', [$team, $app])`, assert `->component('Apps/Identifiers/Index')` and `identifiers.0.identifier`/`version`/`update_url` (drop `app.id` if not present; `app.id` is present via AppContext, keep it).
  - `test_user_can_create_a_credential_upload_and_download_its_file` — the `build_targets` assertion block: `get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))`, assert `->component('Apps/Identifiers/Show')` and `identifier.build_targets.0...` (note: `identifier.` prefix, singular, not `identifiers.0.`).
  - `test_identifier_attached_credentials_are_exposed_as_services` — `get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))`, assert `identifier.services.0...` and `identifier.build_targets` == [].
  - `test_app_icon_is_exposed_on_the_dashboard_and_drilldown_as_an_inline_preview` — the drilldown `get(route('accounts.apps.show', ...))` still works (icon is in AppContext `app.assets`); keep as-is.
  - `test_update_url_is_exposed_on_the_drilldown` / `test_destroy_url_is_exposed_on_the_drilldown` — still valid (`app.urls.update`/`destroy` in AppContext). Keep.
- `AppShowDeleteUrlsTest` — both tests: change `get(route('accounts.apps.show', ...))` to `get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))` and change `identifiers.0.services.0.*` → `identifier.services.0.*`, `identifiers.0.delete_url` → `identifier.delete_url`.
- `CredentialUpdateTest:99` — change to `get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))` and `identifiers.0.build_targets.0...` → `identifier.build_targets.0...`.
- `ForceBuildStatusTest:47` — change to `get(route('accounts.apps.builds.index', [$team, $app]))` and keep `builds.0.can_force_status` / `builds.0.force_status_url`.
- `BuildParametersTest`:
  - line 57 `assertRedirect(route('accounts.apps.show', ...))` → `assertRedirect(route('accounts.apps.builds.index', [$team, $app]))`.
  - lines 69/127 `get(route('accounts.apps.show', ...))` → `get(route('accounts.apps.builds.index', [$team, $app]))`; keep `builds.0.*`. For the `pipelines.0.input_schema` assertion (line 130), pipelines are in AppContext on the builds page — keep it.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS (all green — new `AppOverviewPageTest` + migrated tests + Tasks 2-4 tests).

- [ ] **Step 9: Commit**

```bash
git add apps/platform/app apps/platform/routes/web.php apps/platform/tests
git commit -m "feat(apps): overview page, cut over apps.show, migrate tests"
```

---

## Phase C — Frontend

> Each frontend task: build the component(s), then run `npm run build` (from `apps/platform`) to confirm it compiles, then a manual smoke via the dev server / Playwright. Lift markup verbatim from the ranges given in `resources/js/Pages/Apps/Show.svelte` (still present until Task 11).

### Task 6: Shared helpers (`lib.js`) + modal components

**Files:**
- Create: `resources/js/Pages/Apps/lib.js` and all six `resources/js/Pages/Apps/Modals/*.svelte`

**Interfaces:**
- Produces:
  - `lib.js` named exports: `formatSize(bytes)`, `formatBytes(bytes)`, `formatDate(value)`, `formatDateTime(value)`, `formatParameters(value)`, `buildStatusClass(status)`, `initials(name)`, `parseParameters(value, setError)`, `firstFormError(form, prefix)`, `isExpiring(value)`. Bodies copied verbatim from `Show.svelte` lines 110-117, 244-271, 346.
  - Modal components, each `export`ing the props it needs and emitting nothing (they call Inertia forms directly), self-managed `useForm`:
    - `EditAppModal.svelte` — props: `app`, `open`, `onClose`. Markup from `Show.svelte` lines 784-812 + logic lines 37-52.
    - `ManualBuildModal.svelte` — props: `app`, `identifiers`, `buildProfiles`, `open`, `onClose`. Markup lines 637-651 + logic 70-83, 126, 272-305.
    - `PipelineBuildModal.svelte` — props: `pipeline` (null=closed), `onClose`. Markup lines 653-665 + logic 84, 306-330.
    - `IdentifierFormModal.svelte` — props: `app`, `editing` (identifier|null), `open`, `onClose`. Markup lines 680-696 + logic 54, 139-173.
    - `CredentialFormModal.svelte` — props: `app`, `identifiers`, `credentialTypes`, `buildProfiles`, `editing`, `open`, `onClose`, `onSaved`. Markup lines 698-782 + logic 54-69, 119-124, 174-247.
    - `ForceStatusModal.svelte` — props: `build` (null=closed), `onClose`. Markup lines 667-678 + logic 85, 332-344.

- [ ] **Step 1: Create `lib.js`** with the exact helper bodies from the referenced lines, as named exports.

- [ ] **Step 2: Create each modal component**, importing helpers from `../lib.js`, moving the relevant `useForm` + handlers into the component, and taking the listed props. Replace parent-scoped state (e.g. `showEdit`) with the `open`/`onClose` props. Each modal renders only when its open condition is true.

- [ ] **Step 3: Compile**

Run: `cd apps/platform && npm run build`
Expected: build succeeds, no unresolved imports.

- [ ] **Step 4: Commit**

```bash
git add apps/platform/resources/js/Pages/Apps/lib.js apps/platform/resources/js/Pages/Apps/Modals
git commit -m "feat(apps): extract app page helpers and modal components"
```

### Task 7: `AppLayout.svelte`

**Files:**
- Create: `resources/js/Pages/Apps/AppLayout.svelte`

**Interfaces:**
- Consumes: `AppShell`, `TagEditor`, `EditAppModal`, `ManualBuildModal`, `PipelineBuildModal`, `lib.js`.
- Produces: default-slot layout. Props: `auth`, `flash`, `app`, `pipelines`, `identifiers`, `buildProfiles`, `navigationTeams`, `navigationTeam`, `active`.

- [ ] **Step 1: Build the layout**

Wrap `<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam}>`. Render:
- Breadcrumb (`app.urls.index` → app name) — from `Show.svelte` line 365.
- App header (icon/initials, name, Active badge, description/slug, `TagEditor`) — from lines 367-386, but replace the header action buttons with: an **Edit** button (opens `EditAppModal`) and a **Run build ▾** dropdown button. The dropdown lists `pipelines` (each → opens `PipelineBuildModal` for that pipeline) and a **Manual build** item (opens `ManualBuildModal`). Gate the dropdown/manual on `auth?.team?.permissions?.run_pipelines` exactly as `Show.svelte` line 507 does; when the user lacks the permission, hide the Run-build control.
- Flash banner — line 388.
- Tabs row as **links** (not buttons): Overview → `app.urls.overview`, Identifiers → `app.urls.identifiers_page`, Builds → `app.urls.builds_page`. Highlight the one matching `active`. Use `<Link>` from `@inertiajs/svelte`; reuse the tab styling from lines 390-393.
- `<slot />` for page content.
- Mount `EditAppModal`, `ManualBuildModal`, `PipelineBuildModal` with local open-state.

Local state: `let showEdit=false, showManual=false, launchingPipeline=null, showRunMenu=false`. Dropdown toggles `showRunMenu`; selecting an item closes the menu and opens the matching modal.

- [ ] **Step 2: Compile & commit**

Run: `cd apps/platform && npm run build` → Expected: success.

```bash
git add apps/platform/resources/js/Pages/Apps/AppLayout.svelte
git commit -m "feat(apps): add shared AppLayout with header, tabs and run-build menu"
```

### Task 8: `Overview.svelte`

**Files:**
- Create: `resources/js/Pages/Apps/Overview.svelte`

**Interfaces:**
- Consumes: `AppLayout`, `lib.js`. Props: `auth`, `flash`, `app`, `pipelines`, `identifiers`, `buildProfiles`, `navigationTeams`, `navigationTeam`, `active`, `stats`, `activity`.

- [ ] **Step 1: Build the page**

Render `<AppLayout {...}>` wrapping:
- A stat-card grid: Identifiers (`stats.identifiers`, links `app.urls.identifiers_page`), Builds (`stats.builds`, links `app.urls.builds_page`), Last build (`stats.last_build?.status` + `formatDateTime(stats.last_build?.at)`, links builds page), Credentials (`stats.credentials`).
- Activity feed: iterate `activity`; each row shows an icon by `type`, `title`, `formatDateTime(timestamp)`, wrapped in `<Link href={entry.url}>`. Empty state when `activity.length === 0`.
- Aside: App details (slug/created/updated) — from `Show.svelte` lines 487-490 — and App assets block — lines 492-502 (asset upload uses `router.post(app.urls.assets.replace('__collection__', collection), ...)`, logic from lines 194-198).

- [ ] **Step 2: Compile & commit**

Run: `cd apps/platform && npm run build` → Expected: success.

```bash
git add apps/platform/resources/js/Pages/Apps/Overview.svelte
git commit -m "feat(apps): add Overview page with stats and activity feed"
```

### Task 9: `Identifiers/Index.svelte` + `Identifiers/Show.svelte`

**Files:**
- Create: `resources/js/Pages/Apps/Identifiers/Index.svelte`, `resources/js/Pages/Apps/Identifiers/Show.svelte`

**Interfaces:**
- Consumes: `AppLayout`, `IdentifierFormModal`, `CredentialFormModal`, `TagEditor`, `lib.js`.
- `Index` props: standard AppLayout props + `identifiers` (list w/ `show_url`,`update_url`,`delete_url`,`credentials_count`), `credentialTypes`.
- `Show` props: standard AppLayout props + `identifier` (IdentifierPresenter shape), `credentialTypes`, `buildProfiles`.

- [ ] **Step 1: Build `Index.svelte`**

`<AppLayout active="identifiers">` wrapping a card with the identifier rows (lift the row markup from `Show.svelte` lines 437-451 but make the identifier name/link a `<Link href={identifier.show_url}>` instead of an expand toggle; keep the platform icon, version badges, credentials-count pill, Edit/Delete inline-confirm from lines 444-457). Header "Add identifier" button opens `IdentifierFormModal` (create). Delete uses `router.delete(identifier.delete_url, ...)` with inline confirm (logic lines 99-104). Empty state from lines 481-483.

- [ ] **Step 2: Build `Show.svelte`**

`<AppLayout active="identifiers">` wrapping:
- Identifier header (platform icon, name, bundle id, version badges) + Edit (opens `IdentifierFormModal` with `editing=identifier`) + Delete (inline confirm → `router.delete(identifier.delete_url)`; on success redirect to `app.urls.identifiers_page`).
- Credentials sections — lift the expanded block from `Show.svelte` lines 458-476 (Services / Build targets groups + the `credentialRow` snippet from lines 395-428) — plus "Add credential" opening `CredentialFormModal`. `CredentialFormModal`'s `onSaved` reloads the page (`router.reload()`).
- Provide `allTags` for `TagEditor` suggestions by collecting from `identifier.services`/`build_targets` credential tags (adapt lines 127-137 to the single-identifier payload).

- [ ] **Step 3: Compile & commit**

Run: `cd apps/platform && npm run build` → Expected: success.

```bash
git add apps/platform/resources/js/Pages/Apps/Identifiers
git commit -m "feat(apps): add identifiers list and detail pages"
```

### Task 10: `Builds/Index.svelte` + `Builds/Show.svelte`

**Files:**
- Create: `resources/js/Pages/Apps/Builds/Index.svelte`, `resources/js/Pages/Apps/Builds/Show.svelte`

**Interfaces:**
- Consumes: `AppLayout`, `ForceStatusModal`, `TagEditor`, `lib.js`.
- `Index` props: standard + `builds`. `Show` props: standard + `build`.

- [ ] **Step 1: Build `Index.svelte`**

`<AppLayout active="builds">` wrapping:
- The "Start a build" section — lift `Show.svelte` lines 507-538 — but the pipeline "Run build"/manual buttons should trigger the AppLayout run-build menu. Simplest: keep this section as an in-page shortcut that dispatches the same modals via a small local copy, OR (preferred, DRY) drop the duplicate section and rely on the header "Run build ▾". Choose the header-only approach: omit the in-page start-build block to avoid duplicating modal logic; the header dropdown covers it.
- Build history table — lift lines 540-545, 547-572 — but replace the expand-details button with a `<Link href={build_show_url}>Details</Link>`. Add `show_url` to each build in `BuildPresenter` (add `'show_url' => route('accounts.apps.builds.show', [$team, $app, $build])` in Task 5's presenter usage — see follow-up below). Keep the `TagEditor`, status pill, artifacts pill, Cancel/Force-status actions (Force status opens `ForceStatusModal`).
- Polling: `onMount` interval reloading `only: ['builds']` while any build is active — lift lines 348-358.
- Empty state — line 630.

- [ ] **Step 2: Build `Show.svelte`**

`<AppLayout active="builds">` wrapping a full-page build view:
- Back link to `app.urls.builds_page`.
- Status header: pipeline name / Manual build, reporting_mode badge, status pill (`buildStatusClass`), timestamps (`formatDateTime`), actions: Open run (`build.external_url`), Cancel (`build.can_cancel` → `router.post(build.cancel_url)`), Force status (`build.can_force_status` → `ForceStatusModal`).
- Launch parameters `<pre>` (`formatParameters(build.parameters)`) and Logs `<pre>` when `build.logs` — lift lines 576-579.
- Artifacts section — lift lines 581-622 (list + upload form gated by `build.can_manage_artifacts`; logic from lines 87-97).
- Polling: reload `only: ['build']` while `build` is non-terminal or `build.logs_pending`.

- [ ] **Step 3: Add `show_url` to BuildPresenter**

In `BuildPresenter::present`, add `'show_url' => route('accounts.apps.builds.show', [$team, $app, $build]),` to the returned array. Run `php artisan test --filter AppBuildPageTest` → Expected: still PASS.

- [ ] **Step 4: Compile & commit**

Run: `cd apps/platform && npm run build` → Expected: success.

```bash
git add apps/platform/resources/js/Pages/Apps/Builds apps/platform/app/Support/Apps/BuildPresenter.php
git commit -m "feat(apps): add builds list and detail pages"
```

### Task 11: Remove the old Show page + smoke test

**Files:**
- Delete: `resources/js/Pages/Apps/Show.svelte`

- [ ] **Step 1: Delete the file**

```bash
git rm apps/platform/resources/js/Pages/Apps/Show.svelte
```

- [ ] **Step 2: Confirm nothing references `Apps/Show`**

Run: `grep -rn "Apps/Show" apps/platform/app apps/platform/resources apps/platform/tests`
Expected: no matches.

- [ ] **Step 3: Compile**

Run: `cd apps/platform && npm run build`
Expected: success.

- [ ] **Step 4: Full backend suite**

Run: `cd apps/platform && php artisan test`
Expected: PASS.

- [ ] **Step 5: Manual/Playwright smoke**

Start the dev server; as an owner, navigate: Overview → Identifiers (tab) → an identifier (row link) → Builds (tab) → a build (Details link). Verify: Run build ▾ opens the dropdown from every sub-page and both pipeline + manual modals work; Edit modal works; adding/deleting an identifier and a credential works; artifact upload on a build works; active-build polling refreshes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(apps): remove legacy monolithic Show page"
```

---

## Self-Review Notes

- **Spec coverage:** routes table → Tasks 2-5; presenters/AppContext → Task 1; activity feed synthesis → Task 5 (`AppActivityFeed`); stats → Task 5; AppLayout + tabs + Run-build → Task 7; modals extraction → Task 6; page content (overview/identifiers/builds detail) → Tasks 8-10; polling → Task 10; test coverage (new routes + 403/404, existing green) → Tasks 2-5 + migration in Task 5; old Show removed → Task 11. All spec sections mapped.
- **Type consistency:** presenter signatures in Task 1 Interfaces are reused verbatim in Tasks 2-5; `show_url` added to BuildPresenter in Task 10 Step 3 (used by Builds/Index Task 10 Step 1). AppContext `app.urls.overview/identifiers_page/builds_page` defined in Task 1 and consumed by AppLayout Task 7 and pages.
- **Known follow-up:** `credentials_count` on the identifiers index (Task 2) is the lightweight direct-attachment count; the detail page shows the profile-inclusive count via `IdentifierPresenter`. Documented inline; acceptable divergence.
