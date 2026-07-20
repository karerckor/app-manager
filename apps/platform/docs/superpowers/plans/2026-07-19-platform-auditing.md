# Platform Auditing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "who did what" audit trail via `owen-it/laravel-auditing`, resolve the acting entity through a single shared `ActorContext`, and enrich outgoing webhook payloads with a minimal `actor` block.

**Architecture:** A container-bound `ActorContextManager` holds the "current" `ActorContext` (originator user, optional API key, source channel, IP). HTTP requests populate it via middleware; queue jobs and CI callbacks populate it explicitly via `runAs()`. laravel-auditing's custom resolvers and `WebhookEventRecorder` both read from that manager, so audit rows and webhook payloads share identical actor attribution.

**Tech Stack:** Laravel 13 / PHP 8.3, `owen-it/laravel-auditing` ^14, Sanctum, Inertia, PHPUnit 12.

## Global Constraints

- All application models use **UUID** primary keys and UUID foreign/morph keys. The `audits` table and all its morph columns must use UUID types.
- Multi-tenancy is **Team-based**: every tenant-scoped query filters by `team_id`.
- `source` is always one of exactly: `web`, `api`, `ci_cd`, `system`.
- Webhook `actor` block excludes email/PII: only `{ type, id, display_name, source }`, where `type` is `user`, `api_key`, or `system`.
- Credential secrets (`payload`) and API token values (`token`) must **never** appear in audit `old_values`/`new_values`.
- Follow existing patterns: attribute-based model config, anonymous-class migrations (`return new class extends Migration`), tests extend `Tests\TestCase` with `RefreshDatabase`.
- Run the full suite with `php artisan test`. Run one test with `php artisan test --filter=TestName`.

---

## File Structure

```
app/Support/Audit/
  ActorContext.php            # immutable value object (who + source + request metadata)
  ActorContextManager.php     # singleton: current(), set(), runAs(), fromRequest()
  Resolvers/
    UserResolver.php          # OwenIt UserResolver -> originator
    SourceResolver.php        # OwenIt Resolver -> source column
    ApiKeyIdResolver.php      # OwenIt Resolver -> api_key_id column
    TeamIdResolver.php        # OwenIt Resolver -> team_id column
app/Http/Middleware/SetActorContext.php
app/Console/Commands/PruneAudits.php
app/Http/Controllers/AuditLogController.php
app/Policies/AuditPolicy.php  # (registered via Gate)
config/audit.php              # published + edited
database/migrations/2026_07_19_100000_create_audits_table.php
database/migrations/2026_07_19_110000_add_source_to_webhook_events.php
resources/js/Pages/Accounts/AuditLog/Index.vue   # (match existing Pages dir convention)
```

Existing files modified: `WebhookEventRecorder`, `WebhookEvent` (fillable), `WebhookPayload`, all webhook `record()` call sites, `BuildLifecycle`, `DispatchCiCdRun`, `AppActivityFeed`, `bootstrap/app.php` (middleware + schedule), `app/Models/{MobileApp,AppIdentifier,Credential,BuildRecord,WebhookEndpoint,Integration,ApiKey}.php`, `routes/web.php`.

---

## Task 1: `ActorContext` value object

**Files:**
- Create: `app/Support/Audit/ActorContext.php`
- Test: `tests/Unit/Audit/ActorContextTest.php`

**Interfaces:**
- Produces: `ActorContext` immutable value object with:
  - `__construct(?User $originator, ?ApiKey $apiKey, string $source, ?string $ip, ?string $userAgent)`
  - `static system(): self`
  - `static forUser(User $originator, string $source, ?ApiKey $apiKey = null, ?string $ip = null, ?string $userAgent = null): self`
  - `actorType(): string` → `user` | `api_key` | `system`
  - `toWebhookActor(): array` → `['type'=>..., 'id'=>..., 'display_name'=>..., 'source'=>...]`
  - `toArray(): array` and `static fromArray(array): self` (for job serialization; store ids, re-resolve models lazily)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Audit;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\Audit\ActorContext;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ActorContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_context_has_no_originator(): void
    {
        $ctx = ActorContext::system();

        $this->assertNull($ctx->originator);
        $this->assertSame('system', $ctx->source);
        $this->assertSame('system', $ctx->actorType());
        $this->assertSame(['type' => 'system'], $ctx->toWebhookActor());
    }

    public function test_user_context_webhook_actor_omits_email(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $ctx = ActorContext::forUser($user, 'web', null, '10.0.0.1', 'agent');

        $this->assertSame('user', $ctx->actorType());
        $this->assertSame(
            ['type' => 'user', 'id' => $user->id, 'display_name' => 'Ada', 'source' => 'web'],
            $ctx->toWebhookActor(),
        );
    }

    public function test_api_key_context_reports_api_key_type(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $key = ApiKey::factory()->create(['name' => 'CI Deploy', 'tokenable_id' => $user->id, 'tokenable_type' => User::class]);
        $ctx = ActorContext::forUser($user, 'api', $key, null, null);

        $this->assertSame('api_key', $ctx->actorType());
        $actor = $ctx->toWebhookActor();
        $this->assertSame('api_key', $actor['type']);
        $this->assertSame($key->id, $actor['id']);
        $this->assertSame('CI Deploy', $actor['display_name']);
    }

    public function test_round_trips_through_array(): void
    {
        $user = User::factory()->create();
        $ctx = ActorContext::forUser($user, 'ci_cd');

        $restored = ActorContext::fromArray($ctx->toArray());

        $this->assertSame($user->id, $restored->originator?->id);
        $this->assertSame('ci_cd', $restored->source);
    }
}
```

> Note: if `ApiKey` has no factory, create a minimal one first at `database/factories/ApiKeyFactory.php` returning `name`, random `token` hash, `abilities => ['*']`, and a `team_id`. Check `database/factories/` before assuming.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActorContextTest`
Expected: FAIL — class `App\Support\Audit\ActorContext` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support\Audit;

use App\Models\ApiKey;
use App\Models\User;

final class ActorContext
{
    public function __construct(
        public readonly ?User $originator,
        public readonly ?ApiKey $apiKey,
        public readonly string $source,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public static function system(): self
    {
        return new self(null, null, 'system');
    }

    public static function forUser(
        User $originator,
        string $source,
        ?ApiKey $apiKey = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        return new self($originator, $apiKey, $source, $ip, $userAgent);
    }

    public function actorType(): string
    {
        if ($this->apiKey !== null) {
            return 'api_key';
        }

        return $this->originator !== null ? 'user' : 'system';
    }

    /** @return array<string, mixed> */
    public function toWebhookActor(): array
    {
        $type = $this->actorType();

        if ($type === 'system') {
            return ['type' => 'system'];
        }

        return [
            'type' => $type,
            'id' => $type === 'api_key' ? $this->apiKey?->id : $this->originator?->id,
            'display_name' => $type === 'api_key' ? $this->apiKey?->name : $this->originator?->name,
            'source' => $this->source,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'originator_id' => $this->originator?->id,
            'api_key_id' => $this->apiKey?->id,
            'source' => $this->source,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $user = $data['originator_id'] ? User::find($data['originator_id']) : null;
        $apiKey = $data['api_key_id'] ? ApiKey::find($data['api_key_id']) : null;

        if ($user === null) {
            return self::system();
        }

        return new self($user, $apiKey, $data['source'], $data['ip'] ?? null, $data['user_agent'] ?? null);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ActorContextTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Audit/ActorContext.php tests/Unit/Audit/ActorContextTest.php database/factories/ApiKeyFactory.php
git commit -m "feat(audit): add ActorContext value object"
```

---

## Task 2: `ActorContextManager`

**Files:**
- Create: `app/Support/Audit/ActorContextManager.php`
- Modify: `app/Providers/AppServiceProvider.php` (register as singleton)
- Test: `tests/Unit/Audit/ActorContextManagerTest.php`

**Interfaces:**
- Consumes: `ActorContext` (Task 1).
- Produces: `ActorContextManager` singleton with:
  - `current(): ActorContext` (defaults to `ActorContext::system()`)
  - `set(ActorContext $context): void`
  - `runAs(ActorContext $context, \Closure $callback): mixed` (restores previous context afterward, even on exception)
  - `fromRequest(\Illuminate\Http\Request $request): ActorContext`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Audit;

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class ActorContextManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_system(): void
    {
        $manager = new ActorContextManager();

        $this->assertSame('system', $manager->current()->source);
    }

    public function test_run_as_restores_previous_context(): void
    {
        $manager = new ActorContextManager();
        $user = User::factory()->create();

        $inner = $manager->runAs(ActorContext::forUser($user, 'ci_cd'), function () use ($manager) {
            return $manager->current()->source;
        });

        $this->assertSame('ci_cd', $inner);
        $this->assertSame('system', $manager->current()->source);
    }

    public function test_run_as_restores_context_on_exception(): void
    {
        $manager = new ActorContextManager();
        $user = User::factory()->create();

        try {
            $manager->runAs(ActorContext::forUser($user, 'ci_cd'), function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('system', $manager->current()->source);
    }

    public function test_from_request_resolves_web_session(): void
    {
        $manager = new ActorContextManager();
        $user = User::factory()->create();
        $user->withAccessToken(new TransientToken());

        $request = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9']);
        $request->setUserResolver(fn () => $user);

        $ctx = $manager->fromRequest($request);

        $this->assertSame('web', $ctx->source);
        $this->assertNull($ctx->apiKey);
        $this->assertSame($user->id, $ctx->originator?->id);
    }

    public function test_from_request_resolves_api_key(): void
    {
        $manager = new ActorContextManager();
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $key = ApiKey::factory()->create([
            'team_id' => $team->id,
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
        $user->withAccessToken($key);

        $request = Request::create('/x', 'GET');
        $request->setUserResolver(fn () => $user);

        $ctx = $manager->fromRequest($request);

        $this->assertSame('api', $ctx->source);
        $this->assertSame($key->id, $ctx->apiKey?->id);
    }

    public function test_from_request_without_user_is_system(): void
    {
        $manager = new ActorContextManager();
        $request = Request::create('/x', 'GET');

        $this->assertSame('system', $manager->fromRequest($request)->source);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActorContextManagerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support\Audit;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ActorContextManager
{
    private ActorContext $current;

    public function __construct()
    {
        $this->current = ActorContext::system();
    }

    public function current(): ActorContext
    {
        return $this->current;
    }

    public function set(ActorContext $context): void
    {
        $this->current = $context;
    }

    public function runAs(ActorContext $context, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $context;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    public function fromRequest(Request $request): ActorContext
    {
        $user = $request->user();

        if ($user === null) {
            return ActorContext::system();
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $apiKey = $token instanceof ApiKey ? $token : ApiKey::find($token->getKey());

            return ActorContext::forUser($user, 'api', $apiKey, $request->ip(), $request->userAgent());
        }

        return ActorContext::forUser($user, 'web', null, $request->ip(), $request->userAgent());
    }
}
```

- [ ] **Step 4: Register the singleton**

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->singleton(\App\Support\Audit\ActorContextManager::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ActorContextManagerTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Support/Audit/ActorContextManager.php app/Providers/AppServiceProvider.php tests/Unit/Audit/ActorContextManagerTest.php
git commit -m "feat(audit): add ActorContextManager singleton"
```

---

## Task 3: `SetActorContext` middleware

**Files:**
- Create: `app/Http/Middleware/SetActorContext.php`
- Modify: `bootstrap/app.php` (append to `web` and `api` middleware groups, after auth)
- Test: `tests/Feature/Audit/SetActorContextMiddlewareTest.php`

**Interfaces:**
- Consumes: `ActorContextManager` (Task 2).
- Produces: middleware that calls `$manager->set($manager->fromRequest($request))` then `$next($request)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SetActorContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_web_request_sets_web_context(): void
    {
        Route::middleware(['web', 'auth', \App\Http\Middleware\SetActorContext::class])
            ->get('/_test/actor', fn () => response()->json([
                'source' => app(ActorContextManager::class)->current()->source,
            ]));

        $user = User::factory()->create();
        Team::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user)->get('/_test/actor')->assertJson(['source' => 'web']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SetActorContextMiddlewareTest`
Expected: FAIL — middleware class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Http\Middleware;

use App\Support\Audit\ActorContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActorContext
{
    public function __construct(private readonly ActorContextManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->manager->set($this->manager->fromRequest($request));

        return $next($request);
    }
}
```

- [ ] **Step 4: Register in `bootstrap/app.php`**

In the `->withMiddleware(function (Middleware $middleware) {...})` closure, append the middleware to both groups (place after Sanctum/auth so `$request->user()` is resolved):

```php
$middleware->appendToGroup('web', \App\Http\Middleware\SetActorContext::class);
$middleware->appendToGroup('api', \App\Http\Middleware\SetActorContext::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SetActorContextMiddlewareTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/SetActorContext.php bootstrap/app.php tests/Feature/Audit/SetActorContextMiddlewareTest.php
git commit -m "feat(audit): set actor context from request via middleware"
```

---

## Task 4: Install laravel-auditing + `audits` table + config

**Files:**
- Modify: `composer.json` (require `owen-it/laravel-auditing`)
- Create: `database/migrations/2026_07_19_100000_create_audits_table.php`
- Create/Modify: `config/audit.php`

**Interfaces:**
- Produces: `audits` table with UUID morphs plus custom columns `team_id`, `source`, `api_key_id`; `config/audit.php` referencing resolvers created in Task 5.

- [ ] **Step 1: Install the package**

Run:
```bash
composer require owen-it/laravel-auditing:^14
php artisan vendor:publish --provider="OwenIt\Auditing\AuditingServiceProvider" --tag="config"
```
Expected: `config/audit.php` created; package added to `composer.json`.

- [ ] **Step 2: Write the `audits` migration (UUID-aware, with custom columns)**

Create `database/migrations/2026_07_19_100000_create_audits_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('user', 'audits_user_index');
            $table->string('event');
            $table->uuidMorphs('auditable', 'audits_auditable_index');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();

            // Custom columns populated by App\Support\Audit\Resolvers\*
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            // personal_access_tokens.id is a bigint auto-increment, NOT a UUID — match it.
            $table->unsignedBigInteger('api_key_id')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
```

> `uuidMorphs('user')` produces nullable-incompatible columns by default; laravel-auditing needs `user_id`/`user_type` nullable. If `uuidMorphs` is not nullable in this Laravel version, use `$table->nullableUuidMorphs('user', 'audits_user_index');`.

- [ ] **Step 3: Configure `config/audit.php`**

Edit `config/audit.php`:
- `'enabled' => env('AUDITING_ENABLED', true),`
- Under `'user'`, set `'resolver' => \App\Support\Audit\Resolvers\UserResolver::class,`
- Set the `'resolvers'` array:

```php
'resolvers' => [
    'ip_address' => OwenIt\Auditing\Resolvers\IpAddressResolver::class,
    'user_agent' => OwenIt\Auditing\Resolvers\UserAgentResolver::class,
    'url'        => OwenIt\Auditing\Resolvers\UrlResolver::class,
    'source'     => \App\Support\Audit\Resolvers\SourceResolver::class,
    'api_key_id' => \App\Support\Audit\Resolvers\ApiKeyIdResolver::class,
    'team_id'    => \App\Support\Audit\Resolvers\TeamIdResolver::class,
],
```
- Add a custom key for our prune command: `'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),`

- [ ] **Step 4: Run migration to verify it applies**

Run: `php artisan migrate`
Expected: `audits` table created without error. (Resolver classes are referenced in config but only invoked when a model is audited — added in Task 5, so no runtime error yet.)

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/audit.php database/migrations/2026_07_19_100000_create_audits_table.php
git commit -m "feat(audit): install laravel-auditing and create audits table"
```

---

## Task 5: Custom audit resolvers

**Files:**
- Create: `app/Support/Audit/Resolvers/UserResolver.php`
- Create: `app/Support/Audit/Resolvers/SourceResolver.php`
- Create: `app/Support/Audit/Resolvers/ApiKeyIdResolver.php`
- Create: `app/Support/Audit/Resolvers/TeamIdResolver.php`
- Test: `tests/Unit/Audit/ResolversTest.php`

**Interfaces:**
- Consumes: `ActorContextManager` (Task 2) via `app(ActorContextManager::class)`.
- Produces: four resolvers reading the current `ActorContext`. `UserResolver::resolve()` returns `?User`; `SourceResolver::resolve($auditable)` returns `string`; `ApiKeyIdResolver::resolve($auditable)` returns `?string`; `TeamIdResolver::resolve($auditable)` returns `?string` (auditable's `team_id`, else originator's `current_team_id`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Audit;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use App\Support\Audit\Resolvers\SourceResolver;
use App\Support\Audit\Resolvers\TeamIdResolver;
use App\Support\Audit\Resolvers\UserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolversTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_source_resolvers_read_current_context(): void
    {
        $user = User::factory()->create();
        app(ActorContextManager::class)->set(ActorContext::forUser($user, 'api'));

        $this->assertSame($user->id, UserResolver::resolve()?->id);
        $this->assertSame('api', SourceResolver::resolve());
    }

    public function test_team_id_resolver_prefers_auditable_team(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->assertSame($team->id, TeamIdResolver::resolve($app));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ResolversTest`
Expected: FAIL — resolver classes not found.

- [ ] **Step 3: Write the implementations**

`UserResolver.php`:
```php
<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use Illuminate\Contracts\Auth\Authenticatable;
use OwenIt\Auditing\Contracts\UserResolver as UserResolverContract;

class UserResolver implements UserResolverContract
{
    public static function resolve(): ?Authenticatable
    {
        return app(ActorContextManager::class)->current()->originator;
    }
}
```

`SourceResolver.php`:
```php
<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

class SourceResolver implements Resolver
{
    public static function resolve(?Auditable $auditable = null): string
    {
        return app(ActorContextManager::class)->current()->source;
    }
}
```

`ApiKeyIdResolver.php`:
```php
<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

class ApiKeyIdResolver implements Resolver
{
    public static function resolve(?Auditable $auditable = null): ?string
    {
        return app(ActorContextManager::class)->current()->apiKey?->id;
    }
}
```

`TeamIdResolver.php`:
```php
<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

class TeamIdResolver implements Resolver
{
    public static function resolve(?Auditable $auditable = null): ?string
    {
        $teamId = $auditable?->getAttribute('team_id');

        if ($teamId !== null) {
            return $teamId;
        }

        return app(ActorContextManager::class)->current()->originator?->current_team_id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ResolversTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Audit/Resolvers tests/Unit/Audit/ResolversTest.php
git commit -m "feat(audit): add custom resolvers reading ActorContext"
```

---

## Task 6: Audit domain models + Credential redaction

**Files:**
- Modify: `app/Models/MobileApp.php`, `AppIdentifier.php`, `Credential.php`, `BuildRecord.php`, `WebhookEndpoint.php`, `Integration.php`
- Test: `tests/Feature/Audit/DomainAuditingTest.php`

**Interfaces:**
- Consumes: `audits` table + resolvers (Tasks 4–5), `ActorContextManager` (Task 2).
- Produces: each listed model `implements \OwenIt\Auditing\Contracts\Auditable` and `use \OwenIt\Auditing\Auditable;`. `Credential` sets `protected array $auditExclude = ['payload'];`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Enums\CredentialType;
use App\Models\Audit;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainAuditingTest extends TestCase
{
    use RefreshDatabase;

    private function actAsWebUser(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        app(ActorContextManager::class)->set(ActorContext::forUser($user, 'web', null, '10.0.0.1', 'agent'));

        return [$user, $team];
    }

    public function test_creating_app_writes_audit_with_actor_and_source(): void
    {
        [$user, $team] = $this->actAsWebUser();

        $app = MobileApp::create(['team_id' => $team->id, 'name' => 'Acme', 'slug' => 'acme']);

        $audit = Audit::query()->where('auditable_id', $app->id)->firstOrFail();
        $this->assertSame('created', $audit->event);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('web', $audit->source);
        $this->assertSame($team->id, $audit->team_id);
    }

    public function test_credential_payload_is_never_audited(): void
    {
        [, $team] = $this->actAsWebUser();

        $credential = Credential::create([
            'team_id' => $team->id,
            'type' => CredentialType::cases()[0],
            'name' => 'Signing',
            'payload' => ['secret' => 'super-secret-value'],
        ]);

        $credential->update(['name' => 'Renamed', 'payload' => ['secret' => 'rotated-value']]);

        $audits = Audit::query()->where('auditable_id', $credential->id)->get();
        $encoded = $audits->toJson();
        $this->assertStringNotContainsString('super-secret-value', $encoded);
        $this->assertStringNotContainsString('rotated-value', $encoded);
        $this->assertStringNotContainsString('payload', $encoded);
    }
}
```

> `App\Models\Audit`: laravel-auditing ships `OwenIt\Auditing\Models\Audit`. Either reference that class directly in tests, or create a thin `app/Models/Audit.php` extending it with `use HasUuids` and `protected $casts` for `old_values`/`new_values` as array. Prefer creating `App\Models\Audit` (needed for UUID PK generation and consistent casts) and set `'implementation' => \App\Models\Audit::class` in `config/audit.php`. Do this as Step 3a below.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DomainAuditingTest`
Expected: FAIL — `App\Models\Audit` not found / models not auditable.

- [ ] **Step 3a: Create `App\Models\Audit`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use OwenIt\Auditing\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    use HasUuids;
}
```

Set in `config/audit.php`: `'implementation' => \App\Models\Audit::class,`.

- [ ] **Step 3b: Make each domain model auditable**

For `MobileApp`, `AppIdentifier`, `BuildRecord`, `WebhookEndpoint`, `Integration` add:
```php
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
// class MobileApp extends Model implements Auditable  (add interface to existing signature)
// use ..., AuditableTrait;  (add trait to existing `use` line)
```

For `Credential` add the same, plus:
```php
protected array $auditExclude = ['payload'];
```

> Do NOT audit models whose only `team_id` is indirect and high-churn; those are excluded per spec.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DomainAuditingTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models tests/Feature/Audit/DomainAuditingTest.php config/audit.php
git commit -m "feat(audit): audit domain models and redact credential secrets"
```

---

## Task 7: Audit API keys + Team membership changes

**Files:**
- Modify: `app/Models/ApiKey.php` (auditable + `$auditExclude = ['token']`)
- Modify: `app/Models/Team.php` (record membership audits explicitly)
- Test: `tests/Feature/Audit/SecurityAuditingTest.php`

**Interfaces:**
- Consumes: `App\Models\Audit`, `ActorContextManager`.
- Produces: `ApiKey` auditable with `token` excluded. A `Team::recordMembershipAudit(User $member, string $event, ?string $role): Audit` helper that writes an explicit audit row (`auditable_type = Team::class`, `auditable_id = team id`, `event = 'membership_added'|'membership_removed'|'membership_role_changed'`, member id + role in `new_values`), because belongsToMany pivot changes are not captured by the Auditable trait.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Models\ApiKey;
use App\Models\Audit;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_token_is_excluded_from_audit(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        app(ActorContextManager::class)->set(ActorContext::forUser($owner, 'web'));

        $key = ApiKey::create([
            'team_id' => $team->id,
            'name' => 'CI',
            'token' => hash('sha256', 'plain-secret-token'),
            'abilities' => ['*'],
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);

        $audit = Audit::query()->where('auditable_id', $key->id)->firstOrFail();
        $this->assertStringNotContainsString('plain-secret-token', $audit->toJson());
    }

    public function test_membership_change_records_audit(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $member = User::factory()->create();
        app(ActorContextManager::class)->set(ActorContext::forUser($owner, 'web'));

        $team->recordMembershipAudit($member, 'membership_added', 'member');

        $audit = Audit::query()
            ->where('auditable_type', Team::class)
            ->where('auditable_id', $team->id)
            ->where('event', 'membership_added')
            ->firstOrFail();

        $this->assertSame($member->id, $audit->new_values['member_id']);
        $this->assertSame('member', $audit->new_values['role']);
        $this->assertSame($owner->id, $audit->user_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SecurityAuditingTest`
Expected: FAIL — `recordMembershipAudit` missing / ApiKey not auditable.

- [ ] **Step 3: Implement**

In `ApiKey.php` add the auditable interface + trait and `protected array $auditExclude = ['token'];`.

In `Team.php` add:
```php
use App\Models\Audit;
use App\Support\Audit\ActorContextManager;

public function recordMembershipAudit(User $member, string $event, ?string $role): Audit
{
    $context = app(ActorContextManager::class)->current();

    return Audit::create([
        'user_type' => $context->originator ? User::class : null,
        'user_id' => $context->originator?->id,
        'event' => $event,
        'auditable_type' => self::class,
        'auditable_id' => $this->id,
        'old_values' => [],
        'new_values' => ['member_id' => $member->id, 'role' => $role],
        'source' => $context->source,
        'team_id' => $this->id,
        'api_key_id' => $context->apiKey?->id,
    ]);
}
```

> Wire `recordMembershipAudit` into the existing membership add/remove/role-change code paths (search for `->users()->attach`, `->detach`, and role updates in controllers). Add one call per path. Keep those call-site edits in this task's commit.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SecurityAuditingTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/ApiKey.php app/Models/Team.php tests/Feature/Audit/SecurityAuditingTest.php
git commit -m "feat(audit): audit API keys and team membership changes"
```

---

## Task 8: Audit retention prune command

**Files:**
- Create: `app/Console/Commands/PruneAudits.php`
- Modify: `bootstrap/app.php` (schedule daily) or `routes/console.php`
- Test: `tests/Feature/Audit/PruneAuditsTest.php`

**Interfaces:**
- Consumes: `App\Models\Audit`, `config('audit.retention_days')` (Task 4).
- Produces: `audits:prune` command deleting audits older than `retention_days`; runs as `source = system`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_audits_older_than_retention(): void
    {
        config(['audit.retention_days' => 30]);
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $old = Audit::create([
            'event' => 'created', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $team->id, 'source' => 'system',
        ]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $fresh = Audit::create([
            'event' => 'updated', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $team->id, 'source' => 'system',
        ]);

        $this->artisan('audits:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audits', ['id' => $old->id]);
        $this->assertDatabaseHas('audits', ['id' => $fresh->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PruneAuditsTest`
Expected: FAIL — command `audits:prune` not found.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Audit;
use Illuminate\Console\Command;

class PruneAudits extends Command
{
    protected $signature = 'audits:prune';

    protected $description = 'Delete audit records older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('audit.retention_days', 365);
        $cutoff = now()->subDays($days);

        $deleted = Audit::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} audit record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it**

In `bootstrap/app.php` `->withSchedule(function (Schedule $schedule) {...})` (or `routes/console.php` using `Schedule::command`):
```php
$schedule->command('audits:prune')->daily();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PruneAuditsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PruneAudits.php bootstrap/app.php tests/Feature/Audit/PruneAuditsTest.php
git commit -m "feat(audit): add scheduled audits:prune retention command"
```

---

## Task 9: `source` on webhook_events + recorder accepts `ActorContext`

**Files:**
- Create: `database/migrations/2026_07_19_110000_add_source_to_webhook_events.php`
- Modify: `app/Models/WebhookEvent.php` (add `source` to `#[Fillable]`)
- Modify: `app/Integrations/Webhooks/WebhookEventRecorder.php`
- Test: `tests/Feature/Audit/WebhookActorTest.php`

**Interfaces:**
- Consumes: `ActorContext` (Task 1), `ActorContextManager` (Task 2).
- Produces: `WebhookEventRecorder::record(Team $team, WebhookEventType $eventType, Model|string $aggregate, array $payload, ?ActorContext $actor = null): ?WebhookEvent` — when `$actor` is null it defaults to `app(ActorContextManager::class)->current()`. Writes `payload['actor'] = $actor->toWebhookActor()`, `actor_user_id = $actor->originator?->id`, `source = $actor->source`, `schema_version = 2`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('source')->nullable()->after('actor_user_id');
            $table->index(['team_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
```

Run: `php artisan migrate`. Expected: column added.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorded_event_embeds_web_actor_block_and_v2_schema(): void
    {
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        $user = User::factory()->create(['name' => 'Ada']);
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::cases()[0],
            $app,
            ['id' => $app->id],
            ActorContext::forUser($user, 'web'),
        );

        $this->assertSame(2, $event->schema_version);
        $this->assertSame('web', $event->source);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame(
            ['type' => 'user', 'id' => $user->id, 'display_name' => 'Ada', 'source' => 'web'],
            $event->payload['actor'],
        );
    }

    public function test_system_event_embeds_system_actor(): void
    {
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::cases()[0],
            $app,
            ['id' => $app->id],
            ActorContext::system(),
        );

        $this->assertNull($event->actor_user_id);
        $this->assertSame('system', $event->source);
        $this->assertSame(['type' => 'system'], $event->payload['actor']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=WebhookActorTest`
Expected: FAIL — `record()` still expects `?User`; no `actor` in payload; `source` unset.

- [ ] **Step 4: Update the recorder and model**

In `WebhookEvent.php`, add `'source'` to the `#[Fillable]` list.

Rewrite `WebhookEventRecorder::record()`:
```php
<?php

namespace App\Integrations\Webhooks;

use App\Enums\WebhookEventType;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\Team;
use App\Models\WebhookEvent;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WebhookEventRecorder
{
    public function __construct(private readonly ActorContextManager $actorContext) {}

    /** @param array<string, mixed> $payload */
    public function record(
        Team $team,
        WebhookEventType $eventType,
        Model|string $aggregate,
        array $payload,
        ?ActorContext $actor = null,
    ): ?WebhookEvent {
        if (! config('integrations.enabled') || ! config('integrations.webhooks.enabled')) {
            return null;
        }

        $actor ??= $this->actorContext->current();
        $payload['actor'] = $actor->toWebhookActor();

        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => $eventType,
            'schema_version' => 2,
            'aggregate_type' => is_string($aggregate) ? $aggregate : class_basename($aggregate),
            'aggregate_id' => is_string($aggregate) ? null : $aggregate->getKey(),
            'actor_user_id' => $actor->originator?->id,
            'source' => $actor->source,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        DB::afterCommit(fn () => PublishWebhookEvent::dispatch($event->id)->onQueue('webhooks'));

        return $event;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=WebhookActorTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_19_110000_add_source_to_webhook_events.php app/Models/WebhookEvent.php app/Integrations/Webhooks/WebhookEventRecorder.php tests/Feature/Audit/WebhookActorTest.php
git commit -m "feat(webhooks): embed actor block and source, bump schema_version to 2"
```

---

## Task 10: Migrate `record()` call sites + propagate context into jobs

**Files:**
- Modify: all controllers/actions calling `record(...)` (see list below), `app/Support/Builds/BuildLifecycle.php`, `app/Actions/Integrations/StartPipelineBuild.php`, `app/Actions/Builds/CreateManualBuild.php`, `app/Jobs/Integrations/DispatchCiCdRun.php`
- Test: `tests/Feature/Audit/CiCdActorPropagationTest.php`

**Interfaces:**
- Consumes: `ActorContextManager`, `ActorContext` (Tasks 1–2).
- Produces: HTTP call sites drop the trailing `$request->user()` argument (recorder defaults to current context). Build/CI code passes an explicit `ActorContext`. `DispatchCiCdRun` runs its body inside `runAs()` with the initiator context reconstructed from the run's `requested_by_user_id`.

Call sites to update (remove the trailing `$request->user()` / user argument; the recorder now defaults to the middleware-set context):
`app/Http/Controllers/MobileAppController.php`, `BuildArtifactController.php`, `TagsController.php`, `CredentialController.php`, `AppIdentifierController.php`, `Integrations/WebhookEndpointController.php`, and all `app/Http/Controllers/Api/V1/*Controller.php` recorders.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Enums\BuildStatus;
use App\Enums\WebhookEventType;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdActorPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_lifecycle_attributes_ci_cd_source_to_original_requester(): void
    {
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $user->id,
            'status' => BuildStatus::Pending,
        ]);

        app(BuildLifecycle::class)->start($build);

        $event = WebhookEvent::query()
            ->where('event_type', WebhookEventType::BuildStarted)
            ->firstOrFail();

        $this->assertSame('ci_cd', $event->source);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('user', $event->payload['actor']['type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CiCdActorPropagationTest`
Expected: FAIL — `BuildLifecycle` still passes a `User`, so `source` is null / wrong.

- [ ] **Step 3: Update build/CI code to pass explicit `ActorContext`**

In `BuildLifecycle.php`, replace each `$current->requestedBy` (5th arg) with a helper that builds a `ci_cd` context:
```php
use App\Support\Audit\ActorContext;

private function ciCdActor(BuildRecord $build): ActorContext
{
    $requester = $build->requestedBy;

    return $requester
        ? ActorContext::forUser($requester, 'ci_cd')
        : ActorContext::system();
}
```
and pass `$this->ciCdActor($current)` to every `record(...)` call in the class.

In `StartPipelineBuild.php` and `CreateManualBuild.php`, replace the `$user` actor argument with `ActorContext::forUser($user, app(ActorContextManager::class)->current()->source)` — these run in a request, so reuse the request's source (web/api). Import `ActorContextManager`.

In HTTP controllers (list above), delete the trailing `, $request->user()` argument from each `record(...)` call.

- [ ] **Step 4: Wrap `DispatchCiCdRun` in `runAs`**

In `DispatchCiCdRun::handle()`, at the top, reconstruct and enter the initiator context so any nested `record()` / audits are attributed correctly:
```php
use App\Models\CiCdRun;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;

public function handle(CiCdProviderRegistry $providers, BuildLifecycle $lifecycle, ActorContextManager $actor): void
{
    $run = CiCdRun::query()->with('buildRecord')->findOrFail($this->runId);
    $requester = $run->buildRecord->requestedBy;
    $context = $requester ? ActorContext::forUser($requester, 'ci_cd') : ActorContext::system();

    $actor->runAs($context, function () use ($providers, $lifecycle): void {
        // ... existing handle() body moved here unchanged ...
    });
}
```

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS. Existing webhook tests that assert payload shape may need an added `actor` key — update those assertions in this commit if they fail. (Search: `payload_snapshot`, `->payload` in `tests/`.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers app/Support/Builds/BuildLifecycle.php app/Actions app/Jobs/Integrations/DispatchCiCdRun.php tests/
git commit -m "feat(audit): propagate ActorContext through call sites and CI jobs"
```

---

## Task 11: Team-level Audit Log page

**Files:**
- Create: `app/Policies/AuditPolicy.php` (or a Gate in `AppServiceProvider`)
- Create: `app/Http/Controllers/AuditLogController.php`
- Create: `resources/js/Pages/Accounts/AuditLog/Index.vue` (match the existing `Pages` directory + routing convention — inspect a sibling page first)
- Modify: `routes/web.php`
- Test: `tests/Feature/Audit/AuditLogPageTest.php`

**Interfaces:**
- Consumes: `App\Models\Audit`, existing team-scoping middleware (`team.member`), existing role helpers on `Team`.
- Produces: `GET accounts.audit.index` route rendering `Accounts/AuditLog/Index` with a paginated, team-scoped audit list (actor name, event, auditable type/id, source, created_at) and optional `?app=`, `?user=`, `?event=` filters. Authorized to team owners/admins only.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_owner_sees_team_scoped_audits(): void
    {
        [$user, $team] = $this->owner();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        Audit::create([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => 'created',
            'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => ['name' => 'Acme'],
            'team_id' => $team->id, 'source' => 'web',
        ]);
        // audit from another team must not leak
        $other = Team::factory()->create();
        Audit::create([
            'event' => 'created', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $other->id, 'source' => 'web',
        ]);

        $this->actingAs($user)->get(route('accounts.audit.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/AuditLog/Index')
                ->has('audits.data', 1));
    }

    public function test_non_member_is_forbidden(): void
    {
        [, $team] = $this->owner();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('accounts.audit.index', $team))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditLogPageTest`
Expected: FAIL — route/controller/page missing.

- [ ] **Step 3: Implement controller, route, policy, page**

Inspect a sibling controller/route (e.g. how `accounts.apps.*` routes are grouped with `team.member` middleware in `routes/web.php`) and mirror it. Controller:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        abort_unless($team->roleFor($request->user()) !== null, 403); // owners/admins gate — match existing role API

        $audits = Audit::query()
            ->where('team_id', $team->id)
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($request->query('user'), fn ($q, $u) => $q->where('user_id', $u))
            ->latest()
            ->paginate(25)
            ->through(fn (Audit $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'auditable_type' => class_basename($a->auditable_type),
                'auditable_id' => $a->auditable_id,
                'source' => $a->source,
                'user_id' => $a->user_id,
                'created_at' => $a->created_at?->toISOString(),
            ]);

        return Inertia::render('Accounts/AuditLog/Index', ['audits' => $audits]);
    }
}
```

> Replace `roleFor(...)` / the 403 gate with the project's actual authorization helper (inspect `Team.php:84` `team_user.role` usage and existing controllers). If the project uses Policies, create `AuditPolicy::viewAny(User, Team)` and call `$this->authorize('viewAny', [Audit::class, $team])` instead.

Add the route inside the existing authenticated + `team.member` group in `routes/web.php`:
```php
Route::get('teams/{team}/audit', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('accounts.audit.index');
```

Create `resources/js/Pages/Accounts/AuditLog/Index.vue` mirroring an existing list page (e.g. the builds index) — a table of `audits.data` columns: event, target, source, user, time; with pagination controls. Keep styling consistent with sibling pages.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AuditLogPageTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AuditLogController.php app/Policies routes/web.php resources/js/Pages/Accounts/AuditLog/Index.vue tests/Feature/Audit/AuditLogPageTest.php
git commit -m "feat(audit): add team-level audit log page"
```

---

## Task 12: Rewrite `AppActivityFeed` from audits

**Files:**
- Modify: `app/Support/Apps/AppActivityFeed.php`
- Test: `tests/Feature/Audit/AppActivityFeedTest.php`

**Interfaces:**
- Consumes: `App\Models\Audit`.
- Produces: `AppActivityFeed::for(Team $team, MobileApp $app, int $limit = 12): array` returning entries derived from `audits` scoped to the app and its child records, each `{ type, title, actor, source, timestamp, url }`. Signature unchanged so existing callers keep working.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Apps\AppActivityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_includes_actor_and_source_from_audits(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        Audit::create([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => 'updated',
            'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => ['name' => 'Old'], 'new_values' => ['name' => 'New'],
            'team_id' => $team->id, 'source' => 'web',
        ]);

        $feed = AppActivityFeed::for($team, $app);

        $this->assertNotEmpty($feed);
        $this->assertSame('web', $feed[0]['source']);
        $this->assertSame($user->id, $feed[0]['actor']['id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppActivityFeedTest`
Expected: FAIL — feed has no `source`/`actor` keys.

- [ ] **Step 3: Rewrite `AppActivityFeed::for`**

```php
<?php

namespace App\Support\Apps;

use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;

class AppActivityFeed
{
    public static function for(Team $team, MobileApp $app, int $limit = 12): array
    {
        return Audit::query()
            ->where('team_id', $team->id)
            ->where('auditable_type', MobileApp::class)
            ->where('auditable_id', $app->id)
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Audit $audit) => [
                'type' => $audit->event,
                'title' => class_basename($audit->auditable_type).' '.$audit->event,
                'actor' => $audit->user_id ? [
                    'id' => $audit->user_id,
                    'name' => $audit->user instanceof User ? $audit->user->name : null,
                ] : null,
                'source' => $audit->source,
                'timestamp' => $audit->created_at,
                'url' => route('accounts.apps.show', [$team, $app]),
            ])
            ->all();
    }
}
```

> If existing callers/pages expect build/artifact rows in the feed, extend the query to also include `BuildRecord`/`Artifact` audits scoped to the app (via `whereIn('auditable_id', $app->buildRecords()->pluck('id'))`). Inspect the app Overview page props before finalizing which auditable types to include, and keep the returned key shape backward-compatible where the Vue page reads it.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AppActivityFeedTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS. Fix any Overview page test asserting the old feed shape.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Apps/AppActivityFeed.php tests/Feature/Audit/AppActivityFeedTest.php
git commit -m "feat(audit): back app activity feed with real audit data"
```

---

## Self-Review — spec coverage

- UI → Task 11 (team-level log) + Task 12 (per-app feed). ✅
- Compliance/forensics (actor, channel, IP, field diffs) → Tasks 4–7. ✅
- Enrich webhooks (minimal actor block, schema_version bump, source column) → Tasks 9–10. ✅
- ActorContext originator + source model, async/CI attribution → Tasks 1–2, 10. ✅
- Model coverage (domain + sensitive) → Tasks 6–7; tags audited via parent model JSON diff (no separate tag model — `HasTags` stores JSON). ✅
- Credential/token redaction → Tasks 6–7. ✅
- Tenant isolation (`team_id` scoping) → Tasks 5 (resolver), 11 (query + leak test). ✅
- Retention → Task 8. ✅
- Tests across web/api/ci_cd/system → Tasks 6, 9, 10. ✅

## Notes for the implementer

- Membership auditing (Task 7) is explicit because belongsToMany pivot changes are not captured by the `Auditable` trait — this is intentional, not a shortcut.
- Verify Sanctum's configured personal-access-token model. `ActorContextManager::fromRequest` handles both the base `PersonalAccessToken` and the `ApiKey` subclass, but if `config/sanctum.php` does not point at `ApiKey`, `currentAccessToken()` returns the base model and the `ApiKey::find` fallback resolves it.
- Before Task 11's Vue work, open a sibling page under `resources/js/Pages/Accounts/` to match component/prop/pagination conventions exactly.
