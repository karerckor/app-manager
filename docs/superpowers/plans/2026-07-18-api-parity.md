# REST API Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring `/api/v1/{team:slug}` to feature parity with the web UI — integrations, discovery, pipelines, automatic builds, webhooks/deliveries, and API keys — with pagination on every collection and a dual (scope + role) access-control layer shared with the web.

**Architecture:** New thin `Api\V1` controllers adapt HTTP to the *existing* domain Actions/services/Policies (no business logic is re-implemented). Authorization is declarative: each route carries `abilities:<scope>` (Sanctum) plus a new `team.permission:<permission>` middleware that resolves the token owner's team role. Collections paginate page-based by default; webhook deliveries use cursor pagination; build logs use byte-offset chunking.

**Tech Stack:** Laravel 13, Sanctum token abilities, PHPUnit (`RefreshDatabase`), Eloquent API Resources.

## Global Constraints

- All work happens in `apps/platform`. Run every command from that directory.
- Test runner: `php artisan test` (PHPUnit, class-based, `use RefreshDatabase`). Run a single class with `php artisan test --filter=ClassName`.
- New API controllers live in `App\Http\Controllers\Api\V1`; resources in `App\Http\Resources\Api\V1`.
- Every route is registered inside the existing `Route::prefix('v1/{team:slug}')->middleware(['auth:sanctum','team.member'])->scopeBindings()->name('api.v1.')` group in `routes/api.php`.
- Reuse these existing units; do not duplicate their logic: `CreateManualBuild`, `SaveCiCdPipeline`, `StartPipelineBuild`, `RequestPipelineBuildCancellation`, `CiCdProviderRegistry`, `WorkflowInputValidator`, `WebhookEventRecorder`, `WebhookUrlGuard`, `PublicWebhookUrl` rule, `BuildLog`, `IntegrationPolicy`, `CiCdPipelinePolicy`, `DeliverWebhook` job.
- Pagination defaults: `per_page` default 25, clamp max 100. Build-log `limit` default and max 262144 (`BuildLog::MAX_CHUNK_BYTES`).
- No response may leak: plaintext API-key token (except key create), webhook signing secret (except create/rotate-secret), installation `external_id`, or delivery signatures/payload snapshots.
- Webhook endpoints honor `config('integrations.enabled')` and `config('integrations.webhooks.enabled')` — return 404 when either is off.
- Commit after every task with the shown message.

## File Structure

**Create:**
- `app/Http/Middleware/EnsureTeamPermission.php` — resolves token-owner role, enforces a `TeamPermission`.
- `app/Http/Concerns/ResolvesPageSize.php` — trait: parse+clamp `per_page`.
- `app/Support/ApiKeys/ScopeAuthorization.php` — API-key escalation guard.
- `app/Actions/Integrations/RedeliverWebhook.php` — clone a delivery and re-queue it.
- `app/Http/Controllers/Api/V1/IntegrationController.php`
- `app/Http/Controllers/Api/V1/IntegrationDiscoveryController.php`
- `app/Http/Controllers/Api/V1/CiCdPipelineController.php`
- `app/Http/Controllers/Api/V1/PipelineBuildController.php`
- `app/Http/Controllers/Api/V1/WebhookEndpointController.php`
- `app/Http/Controllers/Api/V1/WebhookDeliveryController.php`
- `app/Http/Controllers/Api/V1/TeamApiKeyController.php`
- `app/Http/Resources/Api/V1/IntegrationResource.php`
- `app/Http/Resources/Api/V1/CiCdPipelineResource.php`
- `app/Http/Resources/Api/V1/WorkflowInputResource.php`
- `app/Http/Resources/Api/V1/WebhookEndpointResource.php`
- `app/Http/Resources/Api/V1/WebhookDeliveryResource.php`
- `app/Http/Resources/Api/V1/ApiKeyResource.php`
- `database/factories/CiCdPipelineFactory.php`, `WebhookEndpointFactory.php`, `WebhookDeliveryFactory.php` — test fixtures.

**Modify:**
- `app/Enums/ApiKeyScope.php` — new cases + `requiredPermission()`.
- `bootstrap/app.php` — register `team.permission` alias.
- `routes/api.php` — add all new routes + `team.permission` on existing routes.
- `app/Http/Controllers/Api/V1/MobileAppController.php` — paginate index.
- `app/Http/Controllers/Api/V1/BuildController.php` — configurable `per_page`, add `cancel`.
- `app/Http/Controllers/Api/V1/BuildLogController.php` — byte-offset chunking.
- `app/Http/Resources/Api/V1/BuildRecordResource.php` — dispatch fields.
- `tests/Feature/Api/V1/ApiKeyScopeTest.php` — update the exact-scope-list assertion.

---

### Task 1: New API scopes and permission mapping

**Files:**
- Modify: `app/Enums/ApiKeyScope.php`
- Modify: `tests/Feature/Api/V1/ApiKeyScopeTest.php:18-34` (the exact-list assertion)
- Test: `tests/Unit/ApiKeyScopeMappingTest.php`

**Interfaces:**
- Produces: `ApiKeyScope` new cases `IntegrationsRead, PipelinesRead, PipelinesWrite, PipelinesRun, WebhooksRead, WebhooksWrite, DeliveriesRead, ApiKeysRead, ApiKeysWrite`; `ApiKeyScope::requiredPermission(): ?TeamPermission` (null means owner-only).

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/ApiKeyScopeMappingTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamPermission;
use PHPUnit\Framework\TestCase;

class ApiKeyScopeMappingTest extends TestCase
{
    public function test_new_scopes_exist(): void
    {
        $values = ApiKeyScope::values();

        foreach ([
            'integrations:read',
            'pipelines:read', 'pipelines:write', 'pipelines:run',
            'webhooks:read', 'webhooks:write',
            'deliveries:read',
            'api-keys:read', 'api-keys:write',
        ] as $scope) {
            $this->assertContains($scope, $values);
        }
    }

    public function test_required_permission_maps_scope_to_permission(): void
    {
        $this->assertSame(TeamPermission::View, ApiKeyScope::AppsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageApplications, ApiKeyScope::AppsWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageCredentials, ApiKeyScope::CredentialsWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::IntegrationsRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::PipelinesWrite->requiredPermission());
        $this->assertSame(TeamPermission::RunPipelines, ApiKeyScope::PipelinesRun->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::WebhooksWrite->requiredPermission());
        $this->assertSame(TeamPermission::ManageIntegrations, ApiKeyScope::DeliveriesRead->requiredPermission());
        $this->assertSame(TeamPermission::ManageTeam, ApiKeyScope::ApiKeysRead->requiredPermission());
        $this->assertNull(ApiKeyScope::ApiKeysWrite->requiredPermission());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ApiKeyScopeMappingTest`
Expected: FAIL — undefined cases / `requiredPermission()` not defined.

- [ ] **Step 3: Add the cases, mapping, labels, and descriptions**

In `app/Enums/ApiKeyScope.php` add cases after `LogsWrite`:

```php
    case IntegrationsRead = 'integrations:read';
    case PipelinesRead = 'pipelines:read';
    case PipelinesWrite = 'pipelines:write';
    case PipelinesRun = 'pipelines:run';
    case WebhooksRead = 'webhooks:read';
    case WebhooksWrite = 'webhooks:write';
    case DeliveriesRead = 'deliveries:read';
    case ApiKeysRead = 'api-keys:read';
    case ApiKeysWrite = 'api-keys:write';
```

Add `use App\Enums\TeamPermission;` is not needed (same namespace `App\Enums`). Add the method:

```php
    public function requiredPermission(): ?TeamPermission
    {
        return match ($this) {
            self::AppsRead, self::IdentifiersRead, self::AssetsRead,
            self::CredentialsRead, self::BuildsRead, self::LogsRead => TeamPermission::View,
            self::AppsWrite, self::IdentifiersWrite, self::AssetsWrite,
            self::BuildsWrite, self::LogsWrite => TeamPermission::ManageApplications,
            self::CredentialsWrite => TeamPermission::ManageCredentials,
            self::IntegrationsRead,
            self::PipelinesRead, self::PipelinesWrite,
            self::WebhooksRead, self::WebhooksWrite,
            self::DeliveriesRead => TeamPermission::ManageIntegrations,
            self::PipelinesRun => TeamPermission::RunPipelines,
            self::ApiKeysRead => TeamPermission::ManageTeam,
            self::ApiKeysWrite => null,
        };
    }
```

Extend `resourceLabel()`'s match with the new resources so `groups()` does not throw:

```php
            'integrations' => 'Integrations',
            'pipelines' => 'Pipelines',
            'webhooks' => 'Webhooks',
            'deliveries' => 'Webhook deliveries',
            'api-keys' => 'API keys',
```

Extend `description()`'s match with an arm for each new case:

```php
            self::IntegrationsRead => 'List integrations and discover repositories, workflows and refs.',
            self::PipelinesRead => 'View pipeline configurations and their inputs.',
            self::PipelinesWrite => 'Create, update and delete pipeline configurations.',
            self::PipelinesRun => 'Dispatch and cancel automated builds.',
            self::WebhooksRead => 'View webhook endpoints.',
            self::WebhooksWrite => 'Create, update, rotate, test and delete webhook endpoints.',
            self::DeliveriesRead => 'View webhook delivery history.',
            self::ApiKeysRead => 'List team API keys.',
            self::ApiKeysWrite => 'Create and revoke team API keys.',
```

- [ ] **Step 4: Update the existing exact-list assertion**

In `tests/Feature/Api/V1/ApiKeyScopeTest.php`, extend the array in `test_all_application_features_have_independent_read_and_write_scopes` to append the nine new scope strings in enum order after `'logs:write'`:

```php
            'logs:write',
            'integrations:read',
            'pipelines:read',
            'pipelines:write',
            'pipelines:run',
            'webhooks:read',
            'webhooks:write',
            'deliveries:read',
            'api-keys:read',
            'api-keys:write',
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ApiKeyScopeMappingTest && php artisan test --filter=ApiKeyScopeTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ApiKeyScope.php tests/Unit/ApiKeyScopeMappingTest.php tests/Feature/Api/V1/ApiKeyScopeTest.php
git commit -m "feat(api): add integration, pipeline, webhook and api-key scopes"
```

---

### Task 2: `team.permission` middleware

**Files:**
- Create: `app/Http/Middleware/EnsureTeamPermission.php`
- Modify: `bootstrap/app.php:38-41` (middleware alias block)
- Test: `tests/Feature/Api/V1/TeamPermissionMiddlewareTest.php`

**Interfaces:**
- Consumes: `Team::allows(User, TeamPermission)` (exists), `ApiKeyScope`/`TeamPermission` enums.
- Produces: route middleware alias `team.permission:<permission-value>`; aborts 403 when the acting user's team role does not grant the permission.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/TeamPermissionMiddlewareTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_passes_and_viewer_is_forbidden_for_write_scope(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);

        $viewer = User::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);

        // Owner with apps:write may create an app.
        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Made by owner'])
            ->assertCreated();

        // Viewer holding the same write scope is blocked by role.
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($viewer->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'Made by viewer'])
            ->assertForbidden();
    }
}
```

(This test also depends on Task 4 wiring `team.permission:manage_applications` onto the apps route. Run it green after Task 4; for this task, verify with Step 4's temporary route below.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeamPermissionMiddlewareTest`
Expected: FAIL — viewer currently receives 201 (no role check yet).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/EnsureTeamPermission.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\TeamPermission;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $team = $request->route('team');
        $user = $request->user();
        $required = TeamPermission::tryFrom($permission);

        abort_unless(
            $required !== null
            && $team instanceof Team
            && $user !== null
            && $team->allows($user, $required),
            403,
        );

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, add to the `$middleware->alias([...])` array:

```php
            'team.permission' => \App\Http\Middleware\EnsureTeamPermission::class,
```

- [ ] **Step 5: Wire onto the apps write route (temporary, finalized in Task 4)**

In `routes/api.php`, add `team.permission:manage_applications` to the `apps.store` route so the test can exercise the middleware now:

```php
        Route::post('/apps', [MobileAppController::class, 'store'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.store');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TeamPermissionMiddlewareTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureTeamPermission.php bootstrap/app.php routes/api.php tests/Feature/Api/V1/TeamPermissionMiddlewareTest.php
git commit -m "feat(api): enforce team role permissions on API routes"
```

---

### Task 3: Pagination helper trait

**Files:**
- Create: `app/Http/Concerns/ResolvesPageSize.php`
- Test: `tests/Unit/ResolvesPageSizeTest.php`

**Interfaces:**
- Produces: trait method `perPage(Request $request, int $default = 25, int $max = 100): int`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/ResolvesPageSizeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Http\Concerns\ResolvesPageSize;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ResolvesPageSizeTest extends TestCase
{
    use ResolvesPageSize;

    public function test_defaults_clamps_and_floors(): void
    {
        $this->assertSame(25, $this->perPage(Request::create('/')));
        $this->assertSame(50, $this->perPage(Request::create('/?per_page=50')));
        $this->assertSame(100, $this->perPage(Request::create('/?per_page=9999')));
        $this->assertSame(25, $this->perPage(Request::create('/?per_page=0')));
        $this->assertSame(25, $this->perPage(Request::create('/?per_page=abc')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ResolvesPageSizeTest`
Expected: FAIL — trait does not exist.

- [ ] **Step 3: Create the trait**

Create `app/Http/Concerns/ResolvesPageSize.php`:

```php
<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait ResolvesPageSize
{
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $value = $request->integer('per_page', $default);

        if ($value < 1) {
            return $default;
        }

        return min($value, $max);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ResolvesPageSizeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Concerns/ResolvesPageSize.php tests/Unit/ResolvesPageSizeTest.php
git commit -m "feat(api): add per-page pagination helper"
```

---

### Task 4: Harden existing routes and normalize pagination

**Files:**
- Modify: `routes/api.php` (add `team.permission:<permission>` to every existing route)
- Modify: `app/Http/Controllers/Api/V1/MobileAppController.php:24-26` (paginate index)
- Modify: `app/Http/Controllers/Api/V1/BuildController.php:24-30` (configurable per_page)
- Test: `tests/Feature/Api/V1/ExistingRoutesAclTest.php`

**Interfaces:**
- Consumes: `EnsureTeamPermission` (Task 2), `ResolvesPageSize` (Task 3), `ApiKeyScope::requiredPermission()` (Task 1).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/ExistingRoutesAclTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExistingRoutesAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_paginated(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        MobileApp::factory()->count(3)->create(['team_id' => $team->id]);

        $this->withToken($owner->createTeamApiKey($team, 'k', [ApiKeyScope::AppsRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/apps?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_viewer_cannot_write_app_even_with_write_scope(): void
    {
        $viewer = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);

        $this->withToken($viewer->createTeamApiKey($team, 'k', [ApiKeyScope::AppsWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/apps", ['name' => 'x'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ExistingRoutesAclTest`
Expected: FAIL — index returns a bare collection (no `meta`), writes not role-guarded.

- [ ] **Step 3: Paginate `MobileAppController@index`**

Replace the body of `index` in `app/Http/Controllers/Api/V1/MobileAppController.php`:

```php
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return MobileAppResource::collection(
            $team->mobileApps()->latest()->paginate($this->perPage($request)),
        );
    }
```

Add `use App\Http\Concerns\ResolvesPageSize;` and `use ResolvesPageSize;` inside the class.

- [ ] **Step 4: Make `BuildController@index` per_page configurable**

In `app/Http/Controllers/Api/V1/BuildController.php`, add `use App\Http\Concerns\ResolvesPageSize;` + `use ResolvesPageSize;`, change the signature to accept `Request $request`, and replace `->paginate(50)` with `->paginate($this->perPage($request, default: 50))`.

- [ ] **Step 5: Add `team.permission` to every existing route**

In `routes/api.php`, append the mapped permission to each route's middleware array. Read scopes → `view`; app/identifier/asset/build/log writes → `manage_applications`; credential writes → `manage_credentials`. Example rewrites:

```php
        Route::get('/credential-types', CredentialTypeController::class)
            ->middleware(['abilities:credentials:read', 'team.permission:view'])
            ->name('credential-types.index');

        Route::get('/apps', [MobileAppController::class, 'index'])->middleware(['abilities:apps:read', 'team.permission:view'])->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->middleware(['abilities:apps:read', 'team.permission:view'])->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.destroy');
```

Apply the same pattern to identifiers (`manage_applications`), assets (`manage_applications`), credentials (read `view`, write `manage_credentials`, including the `file` sub-routes), builds (read `view`, write `manage_applications`), and logs (read `view`, write `manage_applications`).

- [ ] **Step 6: Run the affected tests**

Run: `php artisan test --filter=ExistingRoutesAclTest && php artisan test --filter=ApiKeyScopeTest && php artisan test --filter=PlatformApiTest && php artisan test --filter=BuildApiTest`
Expected: PASS. (These existing suites all use owner-role tokens, so the added role checks pass.)

- [ ] **Step 7: Commit**

```bash
git add routes/api.php app/Http/Controllers/Api/V1/MobileAppController.php app/Http/Controllers/Api/V1/BuildController.php tests/Feature/Api/V1/ExistingRoutesAclTest.php
git commit -m "feat(api): apply dual ACL and paginate existing endpoints"
```

---

### Task 5: Integrations index and show

**Files:**
- Create: `app/Http/Resources/Api/V1/IntegrationResource.php`
- Create: `app/Http/Controllers/Api/V1/IntegrationController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/IntegrationApiTest.php`

**Interfaces:**
- Consumes: `Team::integrations()` (exists), `ResolvesPageSize`.
- Produces: routes `api.v1.integrations.index`, `api.v1.integrations.show`; `IntegrationResource`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/IntegrationApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_integrations_without_leaking_installation_id(): void
    {
        [$user, $team] = $this->ctx();
        Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
            'external_account_name' => 'octo',
        ]);

        $response = $this->withToken($this->key($user, $team, ApiKeyScope::IntegrationsRead))
            ->getJson("/api/v1/{$team->slug}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'GitHub octo')
            ->assertJsonPath('data.0.external_account_name', 'octo')
            ->assertJsonPath('meta.total', 1);

        $this->assertStringNotContainsString('"42"', $response->getContent());
    }

    public function test_admin_role_required(): void
    {
        [$user, $team] = $this->ctx(TeamRole::Contributor);

        $this->withToken($this->key($user, $team, ApiKeyScope::IntegrationsRead))
            ->getJson("/api/v1/{$team->slug}/integrations")
            ->assertForbidden();
    }

    /** @return array{User, Team} */
    private function ctx(TeamRole $role = TeamRole::Owner): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }

    private function key(User $user, Team $team, ApiKeyScope $scope): string
    {
        return $user->createTeamApiKey($team, $scope->value, [$scope->value])->plainTextToken;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=IntegrationApiTest`
Expected: FAIL — route/controller/resource missing (404/500).

- [ ] **Step 3: Create the resource**

Create `app/Http/Resources/Api/V1/IntegrationResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'provider' => $this->provider->value,
            'name' => $this->name,
            'status' => $this->status->value,
            'external_account_name' => $this->external_account_name,
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Api/V1/IntegrationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IntegrationResource;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntegrationController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return IntegrationResource::collection(
            $team->integrations()->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, Integration $integration): IntegrationResource
    {
        abort_unless($integration->team_id === $team->id, 404);

        return new IntegrationResource($integration);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api.php` add inside the group (and import `IntegrationController`):

```php
        Route::get('/integrations', [IntegrationController::class, 'index'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.index');
        Route::get('/integrations/{integration}', [IntegrationController::class, 'show'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.show');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=IntegrationApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/Api/V1/IntegrationResource.php app/Http/Controllers/Api/V1/IntegrationController.php routes/api.php tests/Feature/Api/V1/IntegrationApiTest.php
git commit -m "feat(api): expose integrations index and show"
```

---

### Task 6: Discovery (repositories, workflows, refs)

**Files:**
- Create: `app/Http/Controllers/Api/V1/IntegrationDiscoveryController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/IntegrationDiscoveryApiTest.php`

**Interfaces:**
- Consumes: `CiCdProviderRegistry::for($provider)` → `repositories/pipelines/refs`; `IntegrationStatus::canDispatch()` (exists).
- Produces: routes `integrations.repositories.index`, `integrations.workflows.index`, `integrations.refs.index`, each returning `{data: [...]}`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/IntegrationDiscoveryApiTest.php`. Reuse the fake-provider pattern from `tests/Feature/Integrations/PipelineBuildTest.php` (copy the `provider()` anonymous class and `bindProvider()` helper into this test):

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdRun;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Enums\ProfileType;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_repositories_workflows_and_refs(): void
    {
        [$user, $team, $integration] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::IntegrationsRead->value])->plainTextToken;
        $base = "/api/v1/{$team->slug}/integrations/{$integration->id}";

        $this->withToken($token)->getJson("{$base}/repositories")
            ->assertOk()->assertJsonPath('data.0.fullName', 'octo/mobile');
        $this->withToken($token)->getJson("{$base}/workflows?repository=octo/mobile")
            ->assertOk()->assertJsonPath('data.0.name', 'Release');
        $this->withToken($token)->getJson("{$base}/refs?repository=octo/mobile")
            ->assertOk()->assertJsonPath('data.0.name', 'main');
    }

    public function test_unknown_repository_returns_404(): void
    {
        [$user, $team, $integration] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::IntegrationsRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/workflows?repository=octo/missing")
            ->assertNotFound();
    }

    /** @return array{User, Team, Integration} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);

        return [$user, $team, $integration];
    }

    private function bindProvider(): void
    {
        $provider = new class implements CiCdProvider {
            public function provider(): IntegrationProvider { return IntegrationProvider::GitHub; }
            public function repositories(Integration $i): array { return [new RepositoryData('100', 'mobile', 'octo/mobile', 'main', true, 'https://github.test/octo/mobile')]; }
            public function pipelines(Integration $i, RepositoryData $r): array { return [new PipelineData('200', 'Release', '.github/workflows/release.yml', 'active', 'https://github.test/w/200')]; }
            public function refs(Integration $i, RepositoryData $r): array { return [new RefData('main', 'branch', str_repeat('a', 40))]; }
            public function dispatch(CiCdPipeline $p, string $ref, array $in): DispatchResult { return new DispatchResult('9001', 'u', 'u'); }
            public function cancel(CiCdRun $run): CancelResult { return new CancelResult(true); }
            public function status(CiCdRun $run): ProviderRunStatus { return new ProviderRunStatus('9001', 'completed', 'success', 'u'); }
            public function logs(CiCdRun $run): string { return ''; }
        };
        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$provider]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=IntegrationDiscoveryApiTest`
Expected: FAIL — routes missing.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/V1/IntegrationDiscoveryController.php` (mirrors the web `Integrations\GitHubDiscoveryController` but authorizes via middleware, not Gate):

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationDiscoveryController extends Controller
{
    public function repositories(Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);

        return response()->json(['data' => $providers->for($integration->provider)->repositories($integration)]);
    }

    public function workflows(Request $request, Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);
        $repository = $this->repository($request, $integration, $providers);

        return response()->json(['data' => $providers->for($integration->provider)->pipelines($integration, $repository)]);
    }

    public function refs(Request $request, Team $team, Integration $integration, CiCdProviderRegistry $providers): JsonResponse
    {
        $this->assertDispatchable($team, $integration);
        $repository = $this->repository($request, $integration, $providers);

        return response()->json(['data' => $providers->for($integration->provider)->refs($integration, $repository)]);
    }

    private function repository(Request $request, Integration $integration, CiCdProviderRegistry $providers): RepositoryData
    {
        $data = $request->validate(['repository' => ['required', 'string', 'max:255']]);

        return collect($providers->for($integration->provider)->repositories($integration))
            ->first(fn (RepositoryData $repository) => $repository->fullName === $data['repository'])
            ?? abort(404);
    }

    private function assertDispatchable(Team $team, Integration $integration): void
    {
        abort_unless($integration->team_id === $team->id && $integration->status->canDispatch(), 404);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php` (import the controller):

```php
        Route::get('/integrations/{integration}/repositories', [IntegrationDiscoveryController::class, 'repositories'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.repositories.index');
        Route::get('/integrations/{integration}/workflows', [IntegrationDiscoveryController::class, 'workflows'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.workflows.index');
        Route::get('/integrations/{integration}/refs', [IntegrationDiscoveryController::class, 'refs'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.refs.index');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=IntegrationDiscoveryApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/IntegrationDiscoveryController.php routes/api.php tests/Feature/Api/V1/IntegrationDiscoveryApiTest.php
git commit -m "feat(api): proxy GitHub repository, workflow and ref discovery"
```

---

### Task 7: Pipeline factory, resource, index and show

**Files:**
- Create: `database/factories/CiCdPipelineFactory.php`
- Create: `app/Http/Resources/Api/V1/CiCdPipelineResource.php`
- Create: `app/Http/Controllers/Api/V1/CiCdPipelineController.php` (index + show only in this task)
- Modify: `routes/api.php`
- Modify: `app/Models/CiCdPipeline.php` (add `use HasFactory;` if absent)
- Test: `tests/Feature/Api/V1/CiCdPipelineApiTest.php`

**Interfaces:**
- Produces: `CiCdPipelineFactory`; `CiCdPipelineResource`; routes `integrations.pipelines.index`, `integrations.pipelines.show`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/CiCdPipelineApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdPipelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_shows_pipelines(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Release')
            ->assertJsonPath('data.0.repository_full_name', 'octo/mobile')
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow_name', 'Release');
    }

    /** @return array{User, Team, Integration, CiCdPipeline} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub, 'name' => 'gh',
            'status' => IntegrationStatus::Active, 'external_id' => '42',
        ]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id, 'mobile_app_id' => $app->id,
        ]);

        return [$user, $team, $integration, $pipeline];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CiCdPipelineApiTest`
Expected: FAIL — factory/route/resource missing.

- [ ] **Step 3: Create the factory**

Create `database/factories/CiCdPipelineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\BuildReportingMode;
use App\Enums\ProfileType;
use App\Models\CiCdPipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CiCdPipeline> */
class CiCdPipelineFactory extends Factory
{
    protected $model = CiCdPipeline::class;

    public function definition(): array
    {
        return [
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'enabled' => true,
            'reporting_mode' => BuildReportingMode::Automatic,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [
                'channel' => ['required' => true, 'default' => 'internal', 'type' => 'choice', 'options' => ['internal', 'production'], 'description' => 'Release channel'],
            ],
            'input_defaults' => [],
        ];
    }
}
```

Ensure `app/Models/CiCdPipeline.php` uses the factory trait: add `use Illuminate\Database\Eloquent\Factories\HasFactory;` and `use HasFactory;` in the class body if not already present.

- [ ] **Step 4: Create the resource**

Create `app/Http/Resources/Api/V1/CiCdPipelineResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CiCdPipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'profile_type' => $this->profile_type->value,
            'reporting_mode' => $this->reporting_mode->value,
            'repository_full_name' => $this->repository_full_name,
            'workflow_id' => $this->workflow_id,
            'workflow_name' => $this->workflow_name,
            'workflow_path' => $this->workflow_path,
            'default_ref' => $this->default_ref,
            'input_defaults' => $this->input_defaults ?? [],
            'mobile_app_id' => $this->mobile_app_id,
            'app_identifier_id' => $this->app_identifier_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 5: Create the controller (index + show)**

Create `app/Http/Controllers/Api/V1/CiCdPipelineController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CiCdPipelineResource;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CiCdPipelineController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team, Integration $integration): AnonymousResourceCollection
    {
        $this->assertIntegration($team, $integration);

        return CiCdPipelineResource::collection(
            $integration->pipelines()->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, Integration $integration, CiCdPipeline $pipeline): CiCdPipelineResource
    {
        $this->assertPipeline($team, $integration, $pipeline);

        return new CiCdPipelineResource($pipeline);
    }

    protected function assertIntegration(Team $team, Integration $integration): void
    {
        abort_unless($integration->team_id === $team->id, 404);
    }

    protected function assertPipeline(Team $team, Integration $integration, CiCdPipeline $pipeline): void
    {
        $this->assertIntegration($team, $integration);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);
    }
}
```

Confirm `Integration::pipelines()` exists (it is used by the domain). If it does not, add:

```php
    public function pipelines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CiCdPipeline::class);
    }
```

- [ ] **Step 6: Register the routes**

```php
        Route::get('/integrations/{integration}/pipelines', [CiCdPipelineController::class, 'index'])->middleware(['abilities:pipelines:read', 'team.permission:manage_integrations'])->name('integrations.pipelines.index');
        Route::get('/integrations/{integration}/pipelines/{pipeline}', [CiCdPipelineController::class, 'show'])->middleware(['abilities:pipelines:read', 'team.permission:manage_integrations'])->name('integrations.pipelines.show');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=CiCdPipelineApiTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/factories/CiCdPipelineFactory.php app/Models/CiCdPipeline.php app/Http/Resources/Api/V1/CiCdPipelineResource.php app/Http/Controllers/Api/V1/CiCdPipelineController.php routes/api.php tests/Feature/Api/V1/CiCdPipelineApiTest.php
git commit -m "feat(api): list and show CI/CD pipelines"
```

---

### Task 8: Pipeline create, update, delete

**Files:**
- Modify: `app/Http/Controllers/Api/V1/CiCdPipelineController.php` (add `store`, `update`, `destroy`)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/CiCdPipelineWriteApiTest.php`

**Interfaces:**
- Consumes: `SaveCiCdPipeline::handle(Team, Integration, User, array $data, ?CiCdPipeline)` (exists).
- Produces: routes `integrations.pipelines.store/update/destroy`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/CiCdPipelineWriteApiTest.php`. Copy the fake-provider anonymous class + `bindProvider()` from `IntegrationDiscoveryApiTest` (Task 6), plus a `ctx()` that creates owner/team/app/active-GitHub-integration:

```php
    public function test_creates_updates_and_deletes_a_pipeline(): void
    {
        [$user, $team, $integration, $app] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesWrite->value])->plainTextToken;
        $base = "/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines";

        $created = $this->withToken($token)->postJson($base, [
            'name' => 'Release',
            'mobile_app_id' => $app->id,
            'profile_type' => 'production',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("{$base}/{$created}", [
            'name' => 'Release v2',
            'mobile_app_id' => $app->id,
            'profile_type' => 'production',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ])->assertOk()->assertJsonPath('data.name', 'Release v2');

        $this->withToken($token)->deleteJson("{$base}/{$created}")->assertNoContent();
        $this->assertSoftDeleted('ci_cd_pipelines', ['id' => $created]);
    }

    public function test_read_scope_cannot_write(): void
    {
        [$user, $team, $integration, $app] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRead->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines", ['name' => 'x'])
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CiCdPipelineWriteApiTest`
Expected: FAIL — write routes missing.

- [ ] **Step 3: Add controller methods**

Add to `app/Http/Controllers/Api/V1/CiCdPipelineController.php` (imports: `SaveCiCdPipeline`, `ProfileType`, `BuildReportingMode`, `JsonResponse`, `Rule`, `Response`):

```php
    public function store(Request $request, Team $team, Integration $integration, SaveCiCdPipeline $save): JsonResponse
    {
        $this->assertIntegration($team, $integration);
        $pipeline = $save->handle($team, $integration, $request->user(), $this->validated($request));

        return (new CiCdPipelineResource($pipeline))->response()->setStatusCode(201);
    }

    public function update(Request $request, Team $team, Integration $integration, CiCdPipeline $pipeline, SaveCiCdPipeline $save): CiCdPipelineResource
    {
        $this->assertPipeline($team, $integration, $pipeline);
        $pipeline = $save->handle($team, $integration, $request->user(), $this->validated($request), $pipeline);

        return new CiCdPipelineResource($pipeline);
    }

    public function destroy(Team $team, Integration $integration, CiCdPipeline $pipeline): Response
    {
        $this->assertPipeline($team, $integration, $pipeline);
        $pipeline->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile_app_id' => ['required', 'uuid'],
            'app_identifier_id' => ['nullable', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'repository_full_name' => ['required', 'string', 'max:255'],
            'workflow_id' => ['required', 'string', 'max:255'],
            'default_ref' => ['required', 'string', 'max:255'],
            'input_defaults' => ['sometimes', 'array', 'max:25'],
            'enabled' => ['sometimes', 'boolean'],
            'reporting_mode' => ['sometimes', Rule::enum(BuildReportingMode::class)],
            'build_id_input' => ['nullable', 'string', 'max:255'],
        ]);
    }
```

- [ ] **Step 4: Register the routes**

```php
        Route::post('/integrations/{integration}/pipelines', [CiCdPipelineController::class, 'store'])->middleware(['abilities:pipelines:write', 'team.permission:manage_integrations'])->name('integrations.pipelines.store');
        Route::patch('/integrations/{integration}/pipelines/{pipeline}', [CiCdPipelineController::class, 'update'])->middleware(['abilities:pipelines:write', 'team.permission:manage_integrations'])->name('integrations.pipelines.update');
        Route::delete('/integrations/{integration}/pipelines/{pipeline}', [CiCdPipelineController::class, 'destroy'])->middleware(['abilities:pipelines:write', 'team.permission:manage_integrations'])->name('integrations.pipelines.destroy');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CiCdPipelineWriteApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/CiCdPipelineController.php routes/api.php tests/Feature/Api/V1/CiCdPipelineWriteApiTest.php
git commit -m "feat(api): create, update and delete pipelines"
```

---

### Task 9: Pipeline input schema endpoint

**Files:**
- Create: `app/Http/Resources/Api/V1/WorkflowInputResource.php`
- Modify: `app/Http/Controllers/Api/V1/CiCdPipelineController.php` (add `inputs`)
- Modify: `routes/api.php`
- Test: add `test_returns_input_schema` to `tests/Feature/Api/V1/CiCdPipelineApiTest.php`

**Interfaces:**
- Consumes: `CiCdPipeline->input_schema` (map keyed by input name).
- Produces: route `integrations.pipelines.inputs.index`; `WorkflowInputResource`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/CiCdPipelineApiTest.php`:

```php
    public function test_returns_input_schema(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}/inputs")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'channel')
            ->assertJsonPath('data.0.type', 'choice')
            ->assertJsonPath('data.0.required', true)
            ->assertJsonPath('data.0.options', ['internal', 'production']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CiCdPipelineApiTest`
Expected: FAIL — `inputs` route missing.

- [ ] **Step 3: Create the resource**

Create `app/Http/Resources/Api/V1/WorkflowInputResource.php`. The resource is constructed from a `['name' => ..., 'definition' => [...]]` pair:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowInputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = $this->resource['definition'];

        return [
            'name' => $this->resource['name'],
            'type' => $definition['type'] ?? 'string',
            'required' => (bool) ($definition['required'] ?? false),
            'default' => $definition['default'] ?? null,
            'options' => $definition['options'] ?? [],
            'description' => $definition['description'] ?? null,
        ];
    }
}
```

- [ ] **Step 4: Add the controller method**

Add to `CiCdPipelineController` (import `WorkflowInputResource`, `AnonymousResourceCollection` already imported):

```php
    public function inputs(Team $team, Integration $integration, CiCdPipeline $pipeline): AnonymousResourceCollection
    {
        $this->assertPipeline($team, $integration, $pipeline);

        $schema = collect($pipeline->input_schema ?? [])
            ->map(fn (array $definition, string $name) => ['name' => $name, 'definition' => $definition])
            ->values();

        return WorkflowInputResource::collection($schema);
    }
```

- [ ] **Step 5: Register the route**

```php
        Route::get('/integrations/{integration}/pipelines/{pipeline}/inputs', [CiCdPipelineController::class, 'inputs'])->middleware(['abilities:pipelines:read', 'team.permission:manage_integrations'])->name('integrations.pipelines.inputs.index');
```

Place this line **before** the `pipelines/{pipeline}` show route is not required (distinct suffix), but keep route order tidy.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=CiCdPipelineApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/Api/V1/WorkflowInputResource.php app/Http/Controllers/Api/V1/CiCdPipelineController.php routes/api.php tests/Feature/Api/V1/CiCdPipelineApiTest.php
git commit -m "feat(api): expose pipeline workflow input schema"
```

---

### Task 10: Extend `BuildRecordResource` with dispatch fields

**Files:**
- Modify: `app/Http/Resources/Api/V1/BuildRecordResource.php`
- Test: `tests/Feature/Api/V1/BuildRecordResourceTest.php`

**Interfaces:**
- Consumes: `BuildRecord->ciCdRun` relation (exists; loaded via `->load('ciCdRun')`).
- Produces: resource fields `dispatch_status`, `external_run_url`, `source`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/V1/BuildRecordResourceTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\BuildRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BuildRecordResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_dispatch_fields_null_for_manual_builds(): void
    {
        $build = BuildRecord::factory()->create();

        $array = (new BuildRecordResource($build))->toArray(Request::create('/'));

        $this->assertArrayHasKey('dispatch_status', $array);
        $this->assertArrayHasKey('external_run_url', $array);
        $this->assertNull($array['dispatch_status']);
        $this->assertNull($array['external_run_url']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BuildRecordResourceTest`
Expected: FAIL — keys absent.

- [ ] **Step 3: Extend the resource**

In `app/Http/Resources/Api/V1/BuildRecordResource.php` add to the returned array (before `created_at`):

```php
            'source' => $this->context_snapshot['source'] ?? null,
            'dispatch_status' => $this->whenLoaded('ciCdRun', fn () => $this->ciCdRun?->dispatch_status?->value, $this->ciCdRun?->dispatch_status?->value),
            'external_run_url' => $this->whenLoaded('ciCdRun', fn () => $this->ciCdRun?->external_run_url, $this->ciCdRun?->external_run_url),
```

Note: for a manual build there is no `ciCdRun`, so both resolve to null. If `ciCdRun` relation access triggers a lazy load, that is acceptable for single-resource responses; index responses should eager-load it (see Task 11/12 usage).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BuildRecordResourceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/Api/V1/BuildRecordResource.php tests/Feature/Api/V1/BuildRecordResourceTest.php
git commit -m "feat(api): add CI dispatch fields to build resource"
```

---

### Task 11: Automatic build dispatch endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V1/PipelineBuildController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/PipelineBuildApiTest.php`

**Interfaces:**
- Consumes: `StartPipelineBuild::handle(Team, Integration, CiCdPipeline, User, string $requestUuid, ?string $ref, array $inputs): BuildRecord` (exists).
- Produces: route `integrations.pipelines.runs.store`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/PipelineBuildApiTest.php`. Reuse the fake provider + `ctx()` (owner/team/app/active integration/pipeline) from the integration `PipelineBuildTest` helpers, and `Queue::fake()`:

```php
    public function test_dispatches_one_build_and_is_idempotent(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $this->bindProvider();
        Queue::fake();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRun->value])->plainTextToken;
        $uuid = (string) Str::uuid();
        $url = "/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}/runs";
        $payload = ['request_uuid' => $uuid, 'ref' => 'main', 'inputs' => ['channel' => 'production']];

        $this->withToken($token)->postJson($url, $payload)->assertCreated();
        $this->withToken($token)->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('build_records', 1);
        Queue::assertPushed(DispatchCiCdRun::class, 1);
    }

    public function test_run_scope_and_role_required(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx(TeamRole::Contributor);
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRun->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}/runs", [
                'request_uuid' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PipelineBuildApiTest`
Expected: FAIL — route missing.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/V1/PipelineBuildController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\StartPipelineBuild;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineBuildController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
        StartPipelineBuild $start,
    ): JsonResponse {
        abort_unless($integration->team_id === $team->id, 404);
        abort_unless($pipeline->team_id === $team->id && $pipeline->integration_id === $integration->id, 404);

        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'ref' => ['nullable', 'string', 'max:255'],
            'inputs' => ['sometimes', 'array', 'max:25'],
        ]);

        $build = $start->handle(
            $team,
            $integration,
            $pipeline,
            $request->user(),
            $data['request_uuid'],
            $data['ref'] ?? null,
            $data['inputs'] ?? [],
        );

        return (new BuildRecordResource($build->load('ciCdRun')))->response()->setStatusCode(201);
    }
}
```

- [ ] **Step 4: Register the route**

```php
        Route::post('/integrations/{integration}/pipelines/{pipeline}/runs', [PipelineBuildController::class, 'store'])->middleware(['abilities:pipelines:run', 'team.permission:run_pipelines'])->name('integrations.pipelines.runs.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PipelineBuildApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/PipelineBuildController.php routes/api.php tests/Feature/Api/V1/PipelineBuildApiTest.php
git commit -m "feat(api): dispatch automatic pipeline builds"
```

---

### Task 12: Build cancellation endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/BuildController.php` (add `cancel`)
- Modify: `routes/api.php`
- Test: add `test_cancel_requests_cancellation` to `tests/Feature/Api/V1/PipelineBuildApiTest.php`

**Interfaces:**
- Consumes: `RequestPipelineBuildCancellation::handle(BuildRecord): BuildRecord` (exists).
- Produces: route `apps.builds.cancel`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/PipelineBuildApiTest.php` (dispatch a build via the action, then cancel it over HTTP):

```php
    public function test_cancel_requests_cancellation(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $this->bindProvider();
        Queue::fake();
        $build = app(\App\Actions\Integrations\StartPipelineBuild::class)
            ->handle($team, $integration, $pipeline, $user, (string) Str::uuid());
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRun->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/apps/{$pipeline->mobile_app_id}/builds/{$build->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('build_records', ['id' => $build->id, 'status' => 'cancelled']);
    }
```

Note: the app is bound by `{mobileApp:slug}` on other routes, but cancel uses the mobile app id path segment; the route below binds `{mobileApp}` by slug — adjust the URL to use the app slug. Update the test to resolve the slug: `$app = $pipeline->mobileApp;` then `"/apps/{$app->slug}/builds/{$build->id}/cancel"`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PipelineBuildApiTest`
Expected: FAIL — cancel route missing.

- [ ] **Step 3: Add the controller method**

Add to `app/Http/Controllers/Api/V1/BuildController.php` (import `RequestPipelineBuildCancellation`):

```php
    public function cancel(
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        RequestPipelineBuildCancellation $cancel,
    ): BuildRecordResource {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        $build = $cancel->handle($buildRecord);

        return new BuildRecordResource($build->load('ciCdRun'));
    }
```

- [ ] **Step 4: Register the route**

```php
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/cancel', [BuildController::class, 'cancel'])->middleware(['abilities:pipelines:run', 'team.permission:run_pipelines'])->name('apps.builds.cancel');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PipelineBuildApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/BuildController.php routes/api.php tests/Feature/Api/V1/PipelineBuildApiTest.php
git commit -m "feat(api): cancel builds"
```

---

### Task 13: Build log byte-offset chunking

**Files:**
- Modify: `app/Http/Controllers/Api/V1/BuildLogController.php` (`show`)
- Test: `tests/Feature/Api/V1/BuildLogChunkApiTest.php`

**Interfaces:**
- Consumes: `BuildRecord->log_output` (string), `BuildLog::MAX_CHUNK_BYTES`.
- Produces: `show` supports `?offset=&limit=` returning `content` + `meta{offset,next_offset,returned_bytes,total_bytes}`. No offset/limit → existing single-shot shape preserved.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/BuildLogChunkApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildLogChunkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_byte_range_slices(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => 'ABCDEFGHIJ',
            'logs_updated_at' => now(),
        ]);
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::LogsRead->value])->plainTextToken;
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/logs";

        $this->withToken($token)->getJson("{$url}?offset=0&limit=4")
            ->assertOk()
            ->assertJsonPath('data.content', 'ABCD')
            ->assertJsonPath('data.meta.next_offset', 4)
            ->assertJsonPath('data.meta.total_bytes', 10);

        $this->withToken($token)->getJson("{$url}?offset=8&limit=4")
            ->assertOk()
            ->assertJsonPath('data.content', 'IJ')
            ->assertJsonPath('data.meta.next_offset', null);
    }

    public function test_single_shot_shape_without_params(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id, 'mobile_app_id' => $app->id, 'log_output' => 'hello',
        ]);
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::LogsRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.content', 'hello');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BuildLogChunkApiTest`
Expected: FAIL — no `meta`/byte slicing.

- [ ] **Step 3: Implement chunking in `show`**

Replace `show` in `app/Http/Controllers/Api/V1/BuildLogController.php` (add `use App\Support\Builds\BuildLog;`):

```php
    public function show(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): JsonResponse
    {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        $output = $buildRecord->log_output ?? '';

        if (! $request->has('offset') && ! $request->has('limit')) {
            return response()->json(['data' => [
                'build_id' => $buildRecord->id,
                'content' => $output,
                'updated_at' => $buildRecord->logs_updated_at,
            ]]);
        }

        $total = strlen($output);
        $offset = max(0, $request->integer('offset', 0));
        $limit = $request->integer('limit', BuildLog::MAX_CHUNK_BYTES);
        $limit = $limit < 1 ? BuildLog::MAX_CHUNK_BYTES : min($limit, BuildLog::MAX_CHUNK_BYTES);
        $slice = $offset >= $total ? '' : substr($output, $offset, $limit);
        $nextOffset = $offset + strlen($slice);

        return response()->json(['data' => [
            'build_id' => $buildRecord->id,
            'content' => $slice,
            'updated_at' => $buildRecord->logs_updated_at,
            'meta' => [
                'offset' => $offset,
                'next_offset' => $nextOffset < $total ? $nextOffset : null,
                'returned_bytes' => strlen($slice),
                'total_bytes' => $total,
            ],
        ]]);
    }
```

Add `use Illuminate\Http\Request;` if not already imported.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BuildLogChunkApiTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/BuildLogController.php tests/Feature/Api/V1/BuildLogChunkApiTest.php
git commit -m "feat(api): support byte-offset build log chunking"
```

---

### Task 14: Webhook endpoint factory, resource, index and show

**Files:**
- Create: `database/factories/WebhookEndpointFactory.php`
- Create: `app/Http/Resources/Api/V1/WebhookEndpointResource.php`
- Create: `app/Http/Controllers/Api/V1/WebhookEndpointController.php` (index + show)
- Modify: `routes/api.php`
- Modify: `app/Models/WebhookEndpoint.php` (add `HasFactory`)
- Test: `tests/Feature/Api/V1/WebhookEndpointApiTest.php`

**Interfaces:**
- Consumes: `Team::webhookEndpoints()` (exists); `WebhookEndpoint->integration` relation.
- Produces: `WebhookEndpointFactory`, `WebhookEndpointResource`, routes `webhooks.index`, `webhooks.show`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/WebhookEndpointApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_endpoints_without_leaking_secret(): void
    {
        [$user, $team, $endpoint] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksRead->value])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/webhooks")
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://example.test/hook')
            ->assertJsonPath('data.0.secret_hint', substr($endpoint->secret, -4))
            ->assertJsonPath('data.0.enabled', true);

        $this->assertStringNotContainsString($endpoint->secret, $response->getContent());
        $response->assertJsonMissingPath('data.0.secret');
    }

    /** @return array{User, Team, WebhookEndpoint} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http, 'name' => 'Ops hook',
            'status' => IntegrationStatus::Active,
        ]);
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id,
            'url' => 'https://example.test/hook',
        ]);

        return [$user, $team, $endpoint];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WebhookEndpointApiTest`
Expected: FAIL — factory/route/resource missing.

- [ ] **Step 3: Create the factory**

Create `database/factories/WebhookEndpointFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        $secret = 'whsec_'.Str::random(48);

        return [
            'url' => 'https://example.test/hook',
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'subscribed_events' => [WebhookEventType::subscribableValues()[0] ?? 'mobile_app.created'],
            'consecutive_failures' => 0,
        ];
    }
}
```

Add `HasFactory` to `app/Models/WebhookEndpoint.php` (`use Illuminate\Database\Eloquent\Factories\HasFactory;` and `use HasFactory;` alongside the existing traits).

- [ ] **Step 4: Create the resource**

Create `app/Http/Resources/Api/V1/WebhookEndpointResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\IntegrationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->integration?->name,
            'url' => $this->url,
            'secret_hint' => $this->secret_hint,
            'subscribed_events' => $this->subscribed_events,
            'enabled' => $this->integration?->status === IntegrationStatus::Active,
            'consecutive_failures' => $this->consecutive_failures,
            'last_delivered_at' => $this->last_delivered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 5: Create the controller (index + show)**

Create `app/Http/Controllers/Api/V1/WebhookEndpointController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookEndpointController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        return WebhookEndpointResource::collection(
            $team->webhookEndpoints()->with('integration')->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);

        return new WebhookEndpointResource($webhookEndpoint->load('integration'));
    }

    protected function assertEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }
}
```

- [ ] **Step 6: Register the routes**

```php
        Route::get('/webhooks', [WebhookEndpointController::class, 'index'])->middleware(['abilities:webhooks:read', 'team.permission:manage_integrations'])->name('webhooks.index');
        Route::get('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])->middleware(['abilities:webhooks:read', 'team.permission:manage_integrations'])->name('webhooks.show');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=WebhookEndpointApiTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/factories/WebhookEndpointFactory.php app/Models/WebhookEndpoint.php app/Http/Resources/Api/V1/WebhookEndpointResource.php app/Http/Controllers/Api/V1/WebhookEndpointController.php routes/api.php tests/Feature/Api/V1/WebhookEndpointApiTest.php
git commit -m "feat(api): list and show webhook endpoints"
```

---

### Task 15: Webhook endpoint create, update, delete, rotate-secret, test

**Files:**
- Modify: `app/Http/Controllers/Api/V1/WebhookEndpointController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/WebhookEndpointWriteApiTest.php`

**Interfaces:**
- Consumes: `WebhookUrlGuard`, `PublicWebhookUrl` rule, `WebhookEventRecorder::record(...)`, `WebhookEventType::subscribableValues()` (all exist).
- Produces: routes `webhooks.store/update/destroy/rotate-secret/test`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/WebhookEndpointWriteApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEndpointWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_returns_secret_once_and_rejects_private_url(): void
    {
        [$user, $team] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;
        $event = WebhookEventType::subscribableValues()[0];

        $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'http://127.0.0.1/hook', 'events' => [$event],
        ])->assertUnprocessable();

        $created = $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'https://example.test/hook', 'events' => [$event],
        ])->assertCreated();

        $created->assertJsonPath('data.enabled', true);
        $this->assertNotEmpty($created->json('data.secret'));
        $this->assertStringStartsWith('whsec_', $created->json('data.secret'));
    }

    public function test_rotate_secret_returns_new_secret(): void
    {
        [$user, $team] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;
        $event = WebhookEventType::subscribableValues()[0];
        $id = $this->withToken($token)->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'Ops', 'url' => 'https://example.test/hook', 'events' => [$event],
        ])->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/webhooks/{$id}/rotate-secret")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['secret']]);
    }

    /** @return array{User, Team} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);

        return [$user, $team];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WebhookEndpointWriteApiTest`
Expected: FAIL — write routes missing.

- [ ] **Step 3: Add controller methods**

Add to `WebhookEndpointController` (imports: `IntegrationKind`, `IntegrationProvider`, `IntegrationStatus`, `WebhookEventType`, `Integration`, `WebhookEndpoint`, `WebhookEventRecorder`, `WebhookUrlGuard`, `PublicWebhookUrl`, `DB`, `Str`, `Rule`, `JsonResponse`, `Response`). Inject the services via constructor:

```php
    public function __construct(
        private readonly WebhookUrlGuard $urlGuard,
        private readonly WebhookEventRecorder $events,
    ) {}

    public function store(Request $request, Team $team): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->rules());
        $secret = $this->newSecret();

        $endpoint = DB::transaction(function () use ($team, $request, $data, $secret) {
            $integration = Integration::create([
                'team_id' => $team->id,
                'kind' => IntegrationKind::Webhook,
                'provider' => IntegrationProvider::Http,
                'name' => $data['name'],
                'status' => IntegrationStatus::Active,
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]);
            $endpoint = WebhookEndpoint::create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'url' => $data['url'],
                'secret' => $secret,
                'secret_hint' => substr($secret, -4),
                'subscribed_events' => array_values(array_unique($data['events'])),
            ]);
            $integration->update(['external_id' => $endpoint->id]);

            return $endpoint;
        });

        return (new WebhookEndpointResource($endpoint->load('integration')))
            ->additional(['data' => ['secret' => $secret]])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        $data = $request->validate($this->rules(updating: true));

        DB::transaction(function () use ($webhookEndpoint, $request, $data) {
            $webhookEndpoint->update(array_filter([
                'url' => $data['url'] ?? null,
                'subscribed_events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
            ], fn ($value) => $value !== null));

            $updates = ['updated_by_user_id' => $request->user()->id];
            if (isset($data['name'])) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('enabled', $data)) {
                $updates['status'] = $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled;
                $updates['disabled_at'] = $data['enabled'] ? null : now();
            }
            $webhookEndpoint->integration->update($updates);
        });

        return new WebhookEndpointResource($webhookEndpoint->fresh()->load('integration'));
    }

    public function rotateSecret(Team $team, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        $secret = $this->newSecret();
        $webhookEndpoint->update(['secret' => $secret, 'secret_hint' => substr($secret, -4)]);

        return (new WebhookEndpointResource($webhookEndpoint->load('integration')))
            ->additional(['data' => ['secret' => $secret]])
            ->response();
    }

    public function test(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        DB::transaction(fn () => $this->events->record(
            $team,
            WebhookEventType::WebhookTest,
            $webhookEndpoint,
            ['endpoint_id' => $webhookEndpoint->id],
            $request->user(),
        ));

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    public function destroy(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): Response
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        DB::transaction(function () use ($webhookEndpoint, $request) {
            $webhookEndpoint->integration->update([
                'status' => IntegrationStatus::Disconnected,
                'disconnected_at' => now(),
                'updated_by_user_id' => $request->user()->id,
            ]);
            $webhookEndpoint->delete();
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'url' => [$required, 'string', 'max:2048', new PublicWebhookUrl($this->urlGuard)],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventType::subscribableValues())],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
```

Note: `->additional(['data' => ['secret' => $secret]])` merges `secret` into the resource's `data` object; verify in Step 5 that `data.secret` and `data.id` coexist. If `additional` overwrites rather than merges `data`, instead override by returning `response()->json(['data' => array_merge((new WebhookEndpointResource(...))->resolve($request), ['secret' => $secret])])`.

- [ ] **Step 4: Register the routes**

```php
        Route::post('/webhooks', [WebhookEndpointController::class, 'store'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.store');
        Route::patch('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.update');
        Route::post('/webhooks/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.rotate-secret');
        Route::post('/webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.test');
        Route::delete('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.destroy');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=WebhookEndpointWriteApiTest`
Expected: PASS. If `data.secret` is missing, apply the `array_merge` fallback from Step 3's note and re-run.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/WebhookEndpointController.php routes/api.php tests/Feature/Api/V1/WebhookEndpointWriteApiTest.php
git commit -m "feat(api): manage webhook endpoints and secrets"
```

---

### Task 16: Webhook deliveries index/show and redeliver

**Files:**
- Create: `database/factories/WebhookDeliveryFactory.php`
- Create: `app/Actions/Integrations/RedeliverWebhook.php`
- Create: `app/Http/Resources/Api/V1/WebhookDeliveryResource.php`
- Create: `app/Http/Controllers/Api/V1/WebhookDeliveryController.php`
- Modify: `routes/api.php`
- Modify: `app/Models/WebhookDelivery.php` (add `HasFactory`)
- Test: `tests/Feature/Api/V1/WebhookDeliveryApiTest.php`

**Interfaces:**
- Consumes: `WebhookDelivery` model, `DeliverWebhook::dispatch($id)->onQueue('webhooks')` (exists), `WebhookDeliveryStatus`.
- Produces: `RedeliverWebhook::handle(WebhookDelivery $source): WebhookDelivery`; routes `webhooks.deliveries.index/show/redeliver`; `WebhookDeliveryResource`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/WebhookDeliveryApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_deliveries_with_cursor_and_hides_payload(): void
    {
        [$user, $team, $endpoint, $delivery] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::DeliveriesRead->value])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/webhooks/{$endpoint->id}/deliveries?per_page=1")
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id)
            ->assertJsonStructure(['data', 'meta' => ['per_page', 'next_cursor']]);

        $response->assertJsonMissingPath('data.0.payload_snapshot');
    }

    public function test_redeliver_creates_linked_delivery(): void
    {
        [$user, $team, $endpoint, $delivery] = $this->ctx();
        Queue::fake();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;

        $new = $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/redeliver")
            ->assertCreated()
            ->assertJsonPath('data.redelivery_of_id', $delivery->id)
            ->json('data.id');

        $this->assertNotSame($delivery->id, $new);
        Queue::assertPushed(DeliverWebhook::class);
    }

    /** @return array{User, Team, WebhookEndpoint, WebhookDelivery} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http, 'name' => 'hook',
            'status' => IntegrationStatus::Active,
        ]);
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id,
        ]);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => \App\Enums\WebhookEventType::MobileAppCreated,
            'schema_version' => 1,
            'aggregate_type' => 'MobileApp',
            'aggregate_id' => null,
            'payload' => ['object' => ['id' => 'x']],
            'occurred_at' => now(),
        ]);
        $delivery = WebhookDelivery::factory()->create([
            'team_id' => $team->id,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => WebhookDeliveryStatus::Exhausted,
            'payload_snapshot' => ['id' => $event->id, 'type' => 'mobile_app.created'],
        ]);

        return [$user, $team, $endpoint, $delivery];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WebhookDeliveryApiTest`
Expected: FAIL — factory/action/routes missing.

- [ ] **Step 3: Create the delivery factory**

Create `database/factories/WebhookDeliveryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
            'payload_snapshot' => ['type' => 'mobile_app.created'],
            'response_status' => null,
            'duration_ms' => null,
            'response_body' => null,
            'last_error' => null,
        ];
    }
}
```

Add `HasFactory` to `app/Models/WebhookDelivery.php`.

- [ ] **Step 4: Create the redeliver action**

Create `app/Actions/Integrations/RedeliverWebhook.php`:

```php
<?php

namespace App\Actions\Integrations;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

class RedeliverWebhook
{
    public function handle(WebhookDelivery $source): WebhookDelivery
    {
        $delivery = DB::transaction(fn () => WebhookDelivery::create([
            'team_id' => $source->team_id,
            'webhook_event_id' => $source->webhook_event_id,
            'webhook_endpoint_id' => $source->webhook_endpoint_id,
            'redelivery_of_id' => $source->id,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
            'payload_snapshot' => $source->payload_snapshot,
        ]));

        DB::afterCommit(fn () => DeliverWebhook::dispatch($delivery->id)->onQueue('webhooks'));

        return $delivery;
    }
}
```

- [ ] **Step 5: Create the resource**

Create `app/Http/Resources/Api/V1/WebhookDeliveryResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event?->event_type?->value,
            'status' => $this->status->value,
            'attempt_count' => $this->attempt_count,
            'response_status' => $this->response_status,
            'duration_ms' => $this->duration_ms,
            'last_error' => $this->last_error,
            'redelivery_of_id' => $this->redelivery_of_id,
            'next_attempt_at' => $this->next_attempt_at,
            'delivered_at' => $this->delivered_at,
            'response_preview' => $this->response_body === null ? null : mb_substr($this->response_body, 0, 512),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

Create `app/Http/Controllers/Api/V1/WebhookDeliveryController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\RedeliverWebhook;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookDeliveryController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): AnonymousResourceCollection
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);

        return WebhookDeliveryResource::collection(
            $webhookEndpoint->deliveries()->with('event')->latest()->cursorPaginate($this->perPage($request)),
        );
    }

    public function show(Team $team, WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery): WebhookDeliveryResource
    {
        $this->ensureEnabled();
        $this->assertDelivery($team, $webhookEndpoint, $delivery);

        return new WebhookDeliveryResource($delivery->load('event'));
    }

    public function redeliver(Team $team, WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery, RedeliverWebhook $redeliver): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertDelivery($team, $webhookEndpoint, $delivery);
        $new = $redeliver->handle($delivery);

        return (new WebhookDeliveryResource($new->load('event')))->response()->setStatusCode(201);
    }

    protected function assertEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    protected function assertDelivery(Team $team, WebhookEndpoint $endpoint, WebhookDelivery $delivery): void
    {
        $this->assertEndpoint($team, $endpoint);
        abort_unless($delivery->webhook_endpoint_id === $endpoint->id && $delivery->team_id === $team->id, 404);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }
}
```

- [ ] **Step 7: Register the routes**

```php
        Route::get('/webhooks/{webhookEndpoint}/deliveries', [WebhookDeliveryController::class, 'index'])->middleware(['abilities:deliveries:read', 'team.permission:manage_integrations'])->name('webhooks.deliveries.index');
        Route::get('/webhooks/{webhookEndpoint}/deliveries/{delivery}', [WebhookDeliveryController::class, 'show'])->middleware(['abilities:deliveries:read', 'team.permission:manage_integrations'])->name('webhooks.deliveries.show');
        Route::post('/webhooks/{webhookEndpoint}/deliveries/{delivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])->middleware(['abilities:webhooks:write', 'team.permission:manage_integrations'])->name('webhooks.deliveries.redeliver');
```

Note: `{delivery}` binds `WebhookDelivery` by primary key; ensure the parameter name matches the controller argument `$delivery`.

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=WebhookDeliveryApiTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add database/factories/WebhookDeliveryFactory.php app/Actions/Integrations/RedeliverWebhook.php app/Http/Resources/Api/V1/WebhookDeliveryResource.php app/Http/Controllers/Api/V1/WebhookDeliveryController.php app/Models/WebhookDelivery.php routes/api.php tests/Feature/Api/V1/WebhookDeliveryApiTest.php
git commit -m "feat(api): view and redeliver webhook deliveries"
```

---

### Task 17: API key resource, index and show

**Files:**
- Create: `app/Http/Resources/Api/V1/ApiKeyResource.php`
- Create: `app/Http/Controllers/Api/V1/TeamApiKeyController.php` (index + show)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/TeamApiKeyApiTest.php`

**Interfaces:**
- Consumes: `Team::apiKeys()` (exists), `ApiKey->tokenable` relation.
- Produces: `ApiKeyResource`; routes `api-keys.index`, `api-keys.show`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Api/V1/TeamApiKeyApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamApiKeyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_keys_without_token(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $user->update(['current_team_id' => $team->id]);
        $reader = $user->createTeamApiKey($team, 'reader', [ApiKeyScope::ApiKeysRead->value])->plainTextToken;

        $response = $this->withToken($reader)
            ->getJson("/api/v1/{$team->slug}/api-keys")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'reader');

        $response->assertJsonMissingPath('data.0.token');
    }

    public function test_admin_can_read_but_contributor_cannot(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($user, TeamRole::Contributor);

        $this->withToken($user->createTeamApiKey($team, 'k', [ApiKeyScope::ApiKeysRead->value])->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/api-keys")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeamApiKeyApiTest`
Expected: FAIL — route/resource missing.

- [ ] **Step 3: Create the resource**

Create `app/Http/Resources/Api/V1/ApiKeyResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->abilities ?? [],
            'created_by' => $this->tokenable?->only('id', 'name', 'email'),
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
        ];
    }
}
```

- [ ] **Step 4: Create the controller (index + show)**

Create `app/Http/Controllers/Api/V1/TeamApiKeyController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamApiKeyController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            $team->apiKeys()->with('tokenable:id,name,email')->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, ApiKey $apiKey): ApiKeyResource
    {
        abort_unless($apiKey->team_id === $team->id, 404);

        return new ApiKeyResource($apiKey->load('tokenable:id,name,email'));
    }
}
```

- [ ] **Step 5: Register the routes**

```php
        Route::get('/api-keys', [TeamApiKeyController::class, 'index'])->middleware(['abilities:api-keys:read', 'team.permission:manage_team'])->name('api-keys.index');
        Route::get('/api-keys/{apiKey}', [TeamApiKeyController::class, 'show'])->middleware(['abilities:api-keys:read', 'team.permission:manage_team'])->name('api-keys.show');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TeamApiKeyApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/Api/V1/ApiKeyResource.php app/Http/Controllers/Api/V1/TeamApiKeyController.php routes/api.php tests/Feature/Api/V1/TeamApiKeyApiTest.php
git commit -m "feat(api): list and show team API keys"
```

---

### Task 18: API key create (with escalation guard) and revoke

**Files:**
- Create: `app/Support/ApiKeys/ScopeAuthorization.php`
- Modify: `app/Http/Controllers/Api/V1/TeamApiKeyController.php` (add `store`, `destroy`)
- Modify: `routes/api.php`
- Test: `tests/Unit/ScopeAuthorizationTest.php`, add cases to `tests/Feature/Api/V1/TeamApiKeyApiTest.php`

**Interfaces:**
- Consumes: `ApiKeyScope::requiredPermission()` (Task 1), `Team::allows()`, `User::createTeamApiKey()`, `Team::owner_id`.
- Produces: `ScopeAuthorization::assertAssignable(User $creator, Team $team, array $scopes, ?array $callerAbilities): void`; routes `api-keys.store/destroy`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/ScopeAuthorizationTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\ApiKeyScope;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiKeys\ScopeAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_may_assign_any_scope_but_not_beyond_calling_token(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $guard = new ScopeAuthorization;

        // Web session (no calling token): role bounds only — owner may assign owner-only scope.
        $guard->assertAssignable($owner, $team, [ApiKeyScope::ApiKeysWrite->value], null);
        $this->assertTrue(true);

        // Calling token narrower than request → rejected.
        $this->expectException(ValidationException::class);
        $guard->assertAssignable($owner, $team, [ApiKeyScope::AppsWrite->value], [ApiKeyScope::AppsRead->value]);
    }

    public function test_non_owner_cannot_assign_scope_beyond_role(): void
    {
        $contributor = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $guard = new ScopeAuthorization;

        $this->expectException(ValidationException::class);
        // Contributor lacks ManageIntegrations → cannot mint integrations:read.
        $guard->assertAssignable($contributor, $team, [ApiKeyScope::IntegrationsRead->value], null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ScopeAuthorizationTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the guard**

Create `app/Support/ApiKeys/ScopeAuthorization.php`:

```php
<?php

namespace App\Support\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ScopeAuthorization
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $callerAbilities  Abilities of the token making the request, or null for a web session.
     */
    public function assertAssignable(User $creator, Team $team, array $scopes, ?array $callerAbilities): void
    {
        $isOwner = $creator->id === $team->owner_id;
        $callerHasWildcard = $callerAbilities !== null && in_array('*', $callerAbilities, true);

        foreach ($scopes as $scope) {
            $enum = ApiKeyScope::tryFrom($scope);

            if ($enum === null) {
                $this->deny('Unknown scope: '.$scope);
            }

            $permission = $enum->requiredPermission();

            // Owner-only scopes (requiredPermission() === null) require team ownership.
            if ($permission === null) {
                if (! $isOwner) {
                    $this->deny('You may not grant the scope: '.$scope);
                }
            } elseif (! $team->allows($creator, $permission)) {
                $this->deny('Your role may not grant the scope: '.$scope);
            }

            // A calling token cannot mint a scope it does not itself hold.
            if ($callerAbilities !== null && ! $callerHasWildcard && ! in_array($scope, $callerAbilities, true)) {
                $this->deny('The current token cannot grant the scope: '.$scope);
            }
        }
    }

    private function deny(string $message): never
    {
        throw ValidationException::withMessages(['scopes' => $message]);
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `php artisan test --filter=ScopeAuthorizationTest`
Expected: PASS.

- [ ] **Step 5: Write the failing feature test**

Add to `tests/Feature/Api/V1/TeamApiKeyApiTest.php`:

```php
    public function test_owner_creates_and_revokes_key_but_cannot_exceed_calling_token(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);
        // Calling token holds api-keys:write + apps:read only.
        $caller = $owner->createTeamApiKey($team, 'caller', [
            ApiKeyScope::ApiKeysWrite->value, ApiKeyScope::AppsRead->value,
        ])->plainTextToken;

        // Requesting apps:write exceeds the calling token → 422.
        $this->withToken($caller)->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'too-wide', 'scopes' => [ApiKeyScope::AppsWrite->value],
        ])->assertUnprocessable();

        // Within the caller's scopes → 201 with the token shown once.
        $created = $this->withToken($caller)->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'reader', 'scopes' => [ApiKeyScope::AppsRead->value],
        ])->assertCreated();
        $this->assertNotEmpty($created->json('data.token'));
        $id = $created->json('data.id');

        $this->withToken($caller)->deleteJson("/api/v1/{$team->slug}/api-keys/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_admin_cannot_create_key_owner_only(): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $team->addUser($admin, TeamRole::Admin);

        $this->withToken($admin->createTeamApiKey($team, 'k', [ApiKeyScope::ApiKeysWrite->value])->plainTextToken)
            ->postJson("/api/v1/{$team->slug}/api-keys", [
                'name' => 'x', 'scopes' => [ApiKeyScope::AppsRead->value],
            ])
            ->assertForbidden();
    }
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php artisan test --filter=TeamApiKeyApiTest`
Expected: FAIL — store/destroy routes missing.

- [ ] **Step 7: Add controller methods**

Add to `app/Http/Controllers/Api/V1/TeamApiKeyController.php` (imports: `ApiKeyScope`, `ScopeAuthorization`, `JsonResponse`, `Response`, `Rule`):

```php
    public function store(Request $request, Team $team, ScopeAuthorization $guard): JsonResponse
    {
        $this->authorizeOwner($request, $team);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::enum(ApiKeyScope::class)],
        ]);

        $caller = $request->user()->currentAccessToken();
        $callerAbilities = $caller instanceof \App\Models\ApiKey ? ($caller->abilities ?? []) : null;
        $guard->assertAssignable($request->user(), $team, $data['scopes'], $callerAbilities);

        $token = $request->user()->createTeamApiKey($team, $data['name'], $data['scopes']);

        return response()->json([
            'data' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'scopes' => $token->accessToken->abilities ?? [],
                'token' => $token->plainTextToken,
                'created_at' => $token->accessToken->created_at,
            ],
        ], 201);
    }

    public function destroy(Request $request, Team $team, ApiKey $apiKey): Response
    {
        $this->authorizeOwner($request, $team);
        abort_unless($apiKey->team_id === $team->id, 404);
        $apiKey->delete();

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Team $team): void
    {
        abort_unless($request->user()?->id === $team->owner_id, 403);
    }
```

Add `use App\Models\ApiKey;` (already imported for `show`). Note `$token->accessToken` is the `NewAccessToken`'s underlying model.

- [ ] **Step 8: Register the routes**

```php
        Route::post('/api-keys', [TeamApiKeyController::class, 'store'])->middleware(['abilities:api-keys:write'])->name('api-keys.store');
        Route::delete('/api-keys/{apiKey}', [TeamApiKeyController::class, 'destroy'])->middleware(['abilities:api-keys:write'])->name('api-keys.destroy');
```

(Owner enforcement is done in `authorizeOwner`; no `team.permission` needed since owner-only is stricter than any `TeamPermission`.)

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=TeamApiKeyApiTest && php artisan test --filter=ScopeAuthorizationTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Support/ApiKeys/ScopeAuthorization.php app/Http/Controllers/Api/V1/TeamApiKeyController.php routes/api.php tests/Unit/ScopeAuthorizationTest.php tests/Feature/Api/V1/TeamApiKeyApiTest.php
git commit -m "feat(api): create and revoke API keys with escalation guard"
```

---

### Task 19: Full suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `composer test`
Expected: PASS — all existing and new tests green.

- [ ] **Step 2: If any pre-existing suite fails due to added ACL, inspect and fix**

Likely culprits: tests that hit API routes with a non-owner role or a token lacking the mapped scope. Fix by attaching the correct role in the test's setup (`$team->addUser($user, TeamRole::Owner)`), not by weakening the middleware.

- [ ] **Step 3: Commit any test fixups**

```bash
git add -A
git commit -m "test(api): align existing suites with dual ACL"
```

## Self-Review Notes

- **Spec coverage:** scopes (Task 1), `team.permission` middleware / dual ACL (Tasks 2, 4), pagination helper + normalization + cursor + byte-offset logs (Tasks 3, 4, 13, 16), integrations read (Task 5), discovery (Task 6), pipelines CRUD + inputs (Tasks 7-9), automatic dispatch + cancel (Tasks 11-12), build resource dispatch fields (Task 10), webhooks CRUD + rotate + test (Tasks 14-15), deliveries + redeliver (Task 16), API keys read + create/revoke + escalation guard (Tasks 17-18), resource leakage assertions (Tasks 5, 14, 16, 17), error-mapping (409/422/404 exercised across Tasks 8, 11, 15, 18).
- **Deferred to verification:** GitHub provider 502/504 mapping is exercised indirectly (the `GitHubProvider` maps its own errors); the API surfaces whatever the provider throws. If a dedicated 502 test is desired, add a fake provider that throws in `repositories()` and assert the response status in a follow-up.
- **Consumes/Produces consistency:** `perPage()`, `requiredPermission()`, `assertAssignable()`, `RedeliverWebhook::handle()`, and every route name are defined before first use.
