# Docker Distribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish `apps/platform` as a multi-arch container image on GHCR and ship an example Compose stack that brings up a working App Manager, with the first-run behaviour and the credential modes driven entirely by environment variables.

**Architecture:** One image built from `serversideup/php:8.3-fpm-nginx` serves four roles selected by command (web, queue, scheduler, one-shot init). A one-shot `init` service owns migrations and first-run provisioning; `app`, `queue` and `scheduler` start only after it exits 0. First-run behaviour lives in a new idempotent `php artisan app:bootstrap` command with three modes, so it is unit-testable and the container entrypoint stays a dumb shell script.

**Tech Stack:** Laravel 13 / PHP 8.3, `serversideup/php:8.3-fpm-nginx`, Postgres 17, Redis 8, Docker Buildx + GHCR, GitHub Actions, Astro (docs site).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-04-docker-distribution-design.md`. Read it before starting.
- Image name: `ghcr.io/karerckor/app-manager-platform`.
- Base image: `serversideup/php:8.3.32-fpm-nginx-bookworm` — the patch- and distro-pinned form of `8.3-fpm-nginx`, verified to exist. Pinned so a base rebuild cannot change our runtime silently. Verified facts about it — do not re-derive:
  - `ENTRYPOINT ["docker-php-serversideup-entrypoint"]`, `CMD ["/init"]`, `USER www-data` (uid/gid `33:33`), `WORKDIR /var/www/html`.
  - The entrypoint runs every `/etc/entrypoint.d/*.sh` in version order, **each in a subshell** (`(. "$f")`). A non-zero exit from any script aborts the container start. `export` inside such a script does **not** reach the final process. The container command is visible to those scripts as `$DOCKER_CMD`.
  - HTTP listens on `8080` (`NGINX_HTTP_PORT`), webroot `/var/www/html/public`, `NGINX_CLIENT_MAX_BODY_SIZE=100M`.
  - Built-in `HEALTHCHECK` curls `http://localhost:${NGINX_HTTP_PORT}${HEALTHCHECK_PATH}`; `HEALTHCHECK_PATH` defaults to `/healthcheck` and must be set to `/up`.
  - `AUTORUN_ENABLED` defaults to `false` — the base image's Laravel automations stay off; we run our own steps.
  - `PHP_OPCACHE_ENABLE` defaults to `0` and must be set to `1`.
  - Already bundled: `pdo_pgsql`, `redis`, `zip`, `opcache`, `openssl`, `sodium`, `pcntl`, `curl`. Missing and required: `gd`, `exif`. Install with `install-php-extensions` as `root`, then switch back to `www-data`.
  - `php.ini` already gives `upload_max_filesize=100M`, `post_max_size=100M`, `memory_limit=256M` — matches `MEDIA_MAX_FILE_SIZE_KB=102400`; do not override.
- **Never run `php artisan route:cache`.** `routes/web.php:51` registers `Route::get('/', function () { … })`, and a closure route makes route caching throw. `config:cache` and `view:cache` are safe.
- PHP tests run against a real Postgres (`app_manager_test`); `composer test` from `apps/platform`. Test classes extend `Tests\TestCase` and use `Illuminate\Foundation\Testing\RefreshDatabase`.
- `vendor/bin/pint` must pass on every PHP file touched.
- Docker is available locally (`docker --version` → 29.5.2); every container task is verified locally before commit.
- Commit messages follow the repo's Conventional Commits style (`feat(docker): …`, `fix(installer): …`). Every commit ends with the trailer `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.

## File Structure

**Created**

| Path | Responsibility |
|---|---|
| `apps/platform/app/Actions/Installation/ProvisionFirstOwner.php` | The single transaction that creates the first owner, their team, the owner membership, the audit row, and optionally the `installations` row |
| `apps/platform/app/Console/Commands/BootstrapInstance.php` | `app:bootstrap` — resolves the bootstrap mode and applies it, idempotently |
| `apps/platform/tests/Feature/BootstrapInstanceCommandTest.php` | Command behaviour: all three modes, idempotency, missing/invalid input |
| `apps/platform/tests/Feature/PublicRegistrationTest.php` | `/register` is closed unless `ALLOW_PUBLIC_REGISTRATION=true` |
| `apps/platform/tests/Feature/TrustedProxiesTest.php` | `X-Forwarded-Proto` is honoured only when `TRUSTED_PROXIES` is set |
| `apps/platform/Dockerfile` | Three-stage build of the platform image |
| `apps/platform/.dockerignore` | Keeps `.env`, `vendor`, `node_modules`, local caches out of the build context |
| `apps/platform/docker/app-manager` | Role dispatcher: `queue`, `scheduler`, `init`, `generate-key` |
| `apps/platform/docker/entrypoint.d/60-app-manager.sh` | Per-container start-up: writable dirs, demo `APP_KEY`, config/view cache |
| `deploy/docker/compose.yaml` | The quick-start stack |
| `deploy/docker/.env.example` | Every supported variable, with the mode blocks commented |
| `deploy/docker/README.md` | Quick start + mode preset table |
| `deploy/docker/smoke-test.sh` | Brings the stack up, asserts it works, tears it down — run locally and by CI |
| `.github/workflows/platform-image.yml` | Build (amd64 + arm64), smoke-test, publish manifest to GHCR |
| `apps/docs/src/pages/self-hosting.astro` | Self-hosting documentation page |

**Modified**

| Path | Change |
|---|---|
| `apps/platform/app/Http/Controllers/InstallerController.php` | `store` delegates the provisioning transaction to `ProvisionFirstOwner` |
| `apps/platform/config/installer.php` | New `bootstrap`, `admin`, `public_registration` keys; installer disabled outside `installer` mode |
| `apps/platform/config/app.php` | New `trusted_proxies` key |
| `apps/platform/bootstrap/app.php` | Registers trusted proxies from config |
| `apps/platform/app/Http/Middleware/EnsureSelfHostedInstallation.php` | Blocks `/register` independently of the installer flag |
| `apps/platform/phpunit.xml` | `ALLOW_PUBLIC_REGISTRATION=true` so the existing registration tests keep exercising the flow |
| `apps/platform/.env.example` | Documents the new variables |
| `README.md`, `apps/platform/README.md` | "Run with Docker" sections |
| `apps/docs/src/layouts/DocsLayout.astro` | Nav entry for the Self-hosting page |

---

### Task 1: Extract `ProvisionFirstOwner` from the installer

Pure refactor plus a new seam. `app:bootstrap admin` (Task 2) needs the exact transaction the installer already performs; duplicating it would let the audit row or `current_team_id` drift between the two entry points.

**Files:**
- Create: `apps/platform/app/Actions/Installation/ProvisionFirstOwner.php`
- Modify: `apps/platform/app/Http/Controllers/InstallerController.php` (the `store` method's `DB::transaction` block, around lines 90-127)
- Test: `apps/platform/tests/Feature/SelfHostedInstallerTest.php` (existing — must keep passing unchanged)

**Interfaces:**
- Consumes: `App\Actions\Fortify\CreateNewUser::create(array $input): User`, `App\Models\Team::addUser(User, TeamRole|string): void`, `App\Models\Team::recordMembershipAudit(User, string $event, ?string $role): Audit`, `App\Support\Installation\SelfHostedConfiguration::INSTALLATION_ID` (`'self-hosted'`).
- Produces:
  ```php
  App\Actions\Installation\ProvisionFirstOwner::provision(
      string $name,
      string $email,
      string $password,
      string $teamName,
      ?string $instanceName = null,
      ?array $settings = null,
  ): App\Models\User
  ```
  The `installations` row is written only when both `$instanceName` and `$settings` are given. The installer passes both; `app:bootstrap admin` passes neither, because in a container the environment — not a database row — is the source of configuration truth, and `SelfHostedConfiguration::installed()` already returns true once the users table is populated.

- [ ] **Step 1: Write the failing test**

Add to `apps/platform/tests/Feature/SelfHostedInstallerTest.php` (it already has the `setUp` that enables the installer and sets a fake `app.key`):

```php
    public function test_provision_first_owner_can_create_an_owner_without_recording_an_installation(): void
    {
        $user = app(\App\Actions\Installation\ProvisionFirstOwner::class)->provision(
            name: 'Grace Hopper',
            email: 'grace@example.com',
            password: 'Sup3rSecret!',
            teamName: 'Compiler Crew',
        );

        $team = \App\Models\Team::query()->firstOrFail();

        $this->assertSame('grace@example.com', $user->email);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame('Compiler Crew', $team->name);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('audits', [
            'auditable_type' => \App\Models\Team::class,
            'auditable_id' => $team->id,
            'event' => 'membership_added',
        ]);
        $this->assertDatabaseCount('installations', 0);
    }
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd apps/platform && vendor/bin/phpunit --filter test_provision_first_owner_can_create_an_owner_without_recording_an_installation
```

Expected: FAIL — `Target class [App\Actions\Installation\ProvisionFirstOwner] does not exist.`

- [ ] **Step 3: Write the action**

Create `apps/platform/app/Actions/Installation/ProvisionFirstOwner.php`:

```php
<?php

namespace App\Actions\Installation;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\TeamRole;
use App\Models\Installation;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation\SelfHostedConfiguration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the very first owner of a self-hosted instance.
 *
 * Both entry points use this: the web installer and `php artisan app:bootstrap`
 * in admin mode. Keeping one transaction means the owner membership, its audit
 * row and `current_team_id` cannot drift between them.
 */
class ProvisionFirstOwner
{
    public function __construct(private readonly CreateNewUser $createNewUser) {}

    /**
     * @param  array<string, mixed>|null  $settings  Installer settings; omit to skip the installations row.
     */
    public function provision(
        string $name,
        string $email,
        string $password,
        string $teamName,
        ?string $instanceName = null,
        ?array $settings = null,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $teamName, $instanceName, $settings) {
            $user = $this->createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);

            $team = Team::create([
                'name' => $teamName,
                'owner_id' => $user->id,
            ]);
            $team->addUser($user, TeamRole::Owner);
            $team->recordMembershipAudit($user, 'membership_added', TeamRole::Owner->value);
            $user->update(['current_team_id' => $team->id]);

            if ($instanceName !== null && $settings !== null) {
                Installation::create([
                    'id' => SelfHostedConfiguration::INSTALLATION_ID,
                    'name' => $instanceName,
                    'settings' => $settings,
                    'installed_by_user_id' => $user->id,
                    'installed_at' => now(),
                ]);
            }

            return $user;
        });
    }
}
```

- [ ] **Step 4: Run the new test — it passes**

```bash
cd apps/platform && vendor/bin/phpunit --filter test_provision_first_owner_can_create_an_owner_without_recording_an_installation
```

Expected: PASS.

- [ ] **Step 5: Make the installer use the action**

In `apps/platform/app/Http/Controllers/InstallerController.php`, add the import `use App\Actions\Installation\ProvisionFirstOwner;`, add `ProvisionFirstOwner $provisionFirstOwner` to the `store` signature, and replace the whole `DB::transaction(function () use ($createNewUser, $data, $settings) { … });` block inside the cache lock with:

```php
                return $provisionFirstOwner->provision(
                    name: $data['admin_name'],
                    email: $data['email'],
                    password: $data['password'],
                    teamName: $data['team_name'],
                    instanceName: $data['instance_name'],
                    settings: $settings,
                );
```

Update the closure's `use (...)` list to `use ($configuration, $provisionFirstOwner, $data, $settings)`. Drop the now-unused `CreateNewUser $createNewUser` parameter from `store` and the `use App\Actions\Fortify\CreateNewUser;`, `use App\Enums\TeamRole;`, `use App\Models\Team;`, `use App\Models\Installation;` and `use Illuminate\Support\Facades\DB;` imports **only if** nothing else in the file still uses them — check with `grep -n 'CreateNewUser\|TeamRole\|Team::\|Installation::\|DB::' app/Http/Controllers/InstallerController.php` before deleting any import. `PasswordValidationRules` and the `User` import stay.

- [ ] **Step 6: The whole installer suite still passes**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/SelfHostedInstallerTest.php
```

Expected: PASS, every test. This is the regression gate for the refactor.

- [ ] **Step 7: Lint and commit**

```bash
cd apps/platform && vendor/bin/pint app/Actions/Installation/ProvisionFirstOwner.php app/Http/Controllers/InstallerController.php tests/Feature/SelfHostedInstallerTest.php
cd ../.. && git add apps/platform/app/Actions/Installation/ProvisionFirstOwner.php apps/platform/app/Http/Controllers/InstallerController.php apps/platform/tests/Feature/SelfHostedInstallerTest.php
git commit -m "refactor(installer): extract first-owner provisioning into an action

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `app:bootstrap` with three modes

**Files:**
- Create: `apps/platform/app/Console/Commands/BootstrapInstance.php`
- Create: `apps/platform/tests/Feature/BootstrapInstanceCommandTest.php`
- Modify: `apps/platform/config/installer.php`
- Modify: `apps/platform/.env.example`

**Interfaces:**
- Consumes: `ProvisionFirstOwner::provision(...)` from Task 1; `Database\Seeders\DemoSeeder` (idempotent, creates `demo@example.com` / `Demo123!`).
- Produces:
  - Command `app:bootstrap {--mode=}`, exit `0` on success, `1` on failure.
  - Config keys read by later tasks: `installer.bootstrap` (`'installer'|'admin'|'demo'`), `installer.admin.{name,email,password,team_name}`, `installer.enabled` (now also false outside `installer` mode).

- [ ] **Step 1: Write the failing tests**

Create `apps/platform/tests/Feature/BootstrapInstanceCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BootstrapInstanceCommandTest extends TestCase
{
    use RefreshDatabase;

    private function useAdminMode(): void
    {
        config([
            'installer.bootstrap' => 'admin',
            'installer.admin.name' => 'Ada Lovelace',
            'installer.admin.email' => 'ada@example.com',
            'installer.admin.password' => 'Sup3rSecret!',
            'installer.admin.team_name' => 'Analytical Engine',
        ]);
    }

    public function test_installer_mode_writes_nothing(): void
    {
        config(['installer.bootstrap' => 'installer']);

        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_admin_mode_provisions_the_first_owner(): void
    {
        $this->useAdminMode();

        $this->artisan('app:bootstrap')->assertExitCode(0);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $team = Team::query()->firstOrFail();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('Analytical Engine', $team->name);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_admin_mode_is_idempotent(): void
    {
        $this->useAdminMode();

        $this->artisan('app:bootstrap')->assertExitCode(0);
        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_admin_mode_fails_when_the_email_is_missing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.email' => null]);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_EMAIL')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_mode_fails_when_the_password_is_missing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.password' => null]);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_PASSWORD')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_mode_reports_an_unusable_password_instead_of_throwing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.password' => 'short']);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_PASSWORD')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_mode_seeds_the_demo_account_once(): void
    {
        Storage::fake(config('media-library.disk_name'));
        config(['installer.bootstrap' => 'demo']);

        $this->artisan('app:bootstrap')->assertExitCode(0);
        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'demo@example.com']);
    }

    public function test_an_unknown_mode_fails(): void
    {
        config(['installer.bootstrap' => 'nonsense']);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('nonsense')
            ->assertExitCode(1);
    }

    public function test_the_mode_option_overrides_the_configured_mode(): void
    {
        config(['installer.bootstrap' => 'admin']);

        $this->artisan('app:bootstrap', ['--mode' => 'installer'])->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_web_installer_is_disabled_outside_installer_mode(): void
    {
        Env::getRepository()->set('APP_MANAGER_BOOTSTRAP', 'admin');

        try {
            $config = require config_path('installer.php');
        } finally {
            Env::getRepository()->clear('APP_MANAGER_BOOTSTRAP');
        }

        $this->assertFalse($config['enabled']);
    }

    public function test_the_web_installer_stays_enabled_in_installer_mode(): void
    {
        Env::getRepository()->set('APP_MANAGER_BOOTSTRAP', 'installer');
        Env::getRepository()->set('SELF_HOSTED_INSTALLER_ENABLED', 'true');

        try {
            $config = require config_path('installer.php');
        } finally {
            Env::getRepository()->clear('APP_MANAGER_BOOTSTRAP');
            Env::getRepository()->clear('SELF_HOSTED_INSTALLER_ENABLED');
        }

        $this->assertTrue($config['enabled']);
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/BootstrapInstanceCommandTest.php
```

Expected: FAIL — `The command "app:bootstrap" does not exist.`

- [ ] **Step 3: Extend `config/installer.php`**

Replace the file with:

```php
<?php

$selfHosted = env('APP_DISTRIBUTION', 'self-hosted') === 'self-hosted';
$bootstrap = env('APP_MANAGER_BOOTSTRAP', 'installer');

return [
    /*
    |--------------------------------------------------------------------------
    | First-run bootstrap mode
    |--------------------------------------------------------------------------
    |
    | "installer" hands the first-run experience to the web installer.
    | "admin" creates the first owner from the ADMIN_* variables below.
    | "demo" seeds the disposable demo account. Applied by `app:bootstrap`.
    |
    */
    'bootstrap' => $bootstrap,

    'admin' => [
        'name' => env('ADMIN_NAME', 'Owner'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'team_name' => env('ADMIN_TEAM_NAME', 'My Team'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-hosted web installer
    |--------------------------------------------------------------------------
    |
    | The installer is intentionally unavailable for the hosted distribution.
    | It creates the first owner and stores instance-level integration secrets
    | encrypted with APP_KEY. Disable it for tests or externally provisioned
    | installations.
    |
    | Any bootstrap mode other than "installer" provisions the owner itself, so
    | the installer screen must not be reachable: one variable, not two.
    |
    */
    'enabled' => $selfHosted && env('SELF_HOSTED_INSTALLER_ENABLED', true) && $bootstrap === 'installer',

    /*
    |--------------------------------------------------------------------------
    | Public registration
    |--------------------------------------------------------------------------
    |
    | Fortify registration is closed by default. A self-hosted instance gets
    | its accounts from the installer, from `app:bootstrap`, or from a team
    | invitation — never from a stranger hitting /register.
    |
    */
    'public_registration' => (bool) env('ALLOW_PUBLIC_REGISTRATION', false),
];
```

- [ ] **Step 4: Write the command**

Create `apps/platform/app/Console/Commands/BootstrapInstance.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Installation\ProvisionFirstOwner;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapInstance extends Command
{
    protected $signature = 'app:bootstrap {--mode= : installer, admin or demo. Defaults to APP_MANAGER_BOOTSTRAP.}';

    protected $description = 'Apply the configured first-run bootstrap mode. Safe to run on every start.';

    private const MODES = ['installer', 'admin', 'demo'];

    public function handle(ProvisionFirstOwner $provisionFirstOwner): int
    {
        $mode = (string) ($this->option('mode') ?: config('installer.bootstrap'));

        if (! in_array($mode, self::MODES, true)) {
            $this->error(sprintf(
                'Unknown bootstrap mode "%s". Set APP_MANAGER_BOOTSTRAP to one of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));

            return self::FAILURE;
        }

        return match ($mode) {
            'installer' => $this->runInstallerMode(),
            'admin' => $this->runAdminMode($provisionFirstOwner),
            'demo' => $this->runDemoMode(),
        };
    }

    private function runInstallerMode(): int
    {
        $this->info('Bootstrap mode "installer": open '.rtrim((string) config('app.url'), '/').'/install to create the first owner.');

        return self::SUCCESS;
    }

    private function runAdminMode(ProvisionFirstOwner $provisionFirstOwner): int
    {
        $email = (string) config('installer.admin.email');
        $password = (string) config('installer.admin.password');

        if ($email === '') {
            $this->error('ADMIN_EMAIL is required when APP_MANAGER_BOOTSTRAP=admin.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('ADMIN_PASSWORD is required when APP_MANAGER_BOOTSTRAP=admin.');

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->info('Bootstrap mode "admin": an account already exists, leaving it untouched.');

            return self::SUCCESS;
        }

        try {
            $user = $provisionFirstOwner->provision(
                name: (string) config('installer.admin.name'),
                email: $email,
                password: $password,
                teamName: (string) config('installer.admin.team_name'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error('ADMIN_EMAIL / ADMIN_PASSWORD rejected: '.$message);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Created owner %s with team "%s".', $user->email, (string) $user->currentTeam?->name));

        return self::SUCCESS;
    }

    private function runDemoMode(): int
    {
        if (User::query()->exists()) {
            $this->info('Bootstrap mode "demo": an account already exists, skipping the demo seed.');

            return self::SUCCESS;
        }

        // DemoSeeder is called directly: DatabaseSeeder refuses to seed outside
        // local/development/testing, and a container runs APP_ENV=production.
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->info('Seeded the demo account: demo@example.com / Demo123! (API key: demo-api-key).');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests — all pass**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/BootstrapInstanceCommandTest.php
```

Expected: PASS (11 tests). If `test_admin_mode_reports_an_unusable_password_instead_of_throwing` fails because `Password::default()` accepts `short`, check `Password::defaults()` in `app/Providers/` and pick a value that genuinely violates it (e.g. `'a'`).

- [ ] **Step 6: Document the variables in `apps/platform/.env.example`**

Append after the `SELF_HOSTED_INSTALLER_ENABLED=true` line:

```dotenv
# installer | admin | demo. Anything other than "installer" disables the web installer.
APP_MANAGER_BOOTSTRAP=installer
# Used when APP_MANAGER_BOOTSTRAP=admin.
ADMIN_NAME="Owner"
ADMIN_EMAIL=
ADMIN_PASSWORD=
ADMIN_TEAM_NAME="My Team"
```

- [ ] **Step 7: Full suite, lint, commit**

```bash
cd apps/platform && composer test && vendor/bin/pint app/Console/Commands/BootstrapInstance.php config/installer.php tests/Feature/BootstrapInstanceCommandTest.php
cd ../.. && git add apps/platform/app/Console/Commands/BootstrapInstance.php apps/platform/config/installer.php apps/platform/tests/Feature/BootstrapInstanceCommandTest.php apps/platform/.env.example
git commit -m "feat(installer): add app:bootstrap with installer, admin and demo modes

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Close public registration, honour trusted proxies

Both are prerequisites for exposing the container safely. With the installer disabled (which `admin` and `demo` modes now do), `EnsureSelfHostedInstallation` returns early and Fortify's `/register` becomes reachable to anyone. And behind the Compose TLS profile, Laravel would see plain HTTP.

**Files:**
- Modify: `apps/platform/app/Http/Middleware/EnsureSelfHostedInstallation.php`
- Modify: `apps/platform/bootstrap/app.php`
- Modify: `apps/platform/config/app.php`
- Modify: `apps/platform/phpunit.xml`
- Modify: `apps/platform/.env.example`
- Create: `apps/platform/tests/Feature/PublicRegistrationTest.php`
- Create: `apps/platform/tests/Feature/TrustedProxiesTest.php`

**Interfaces:**
- Consumes: `installer.public_registration` from Task 2.
- Produces: `app.trusted_proxies` config key (string; `'*'` or a comma-separated list; empty means trust nobody).

- [ ] **Step 1: Write the failing registration test**

Create `apps/platform/tests/Feature/PublicRegistrationTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default_even_when_the_installer_is_disabled(): void
    {
        config([
            'installer.public_registration' => false,
            'installer.enabled' => false,
        ]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'Sup3rSecret!',
            'password_confirmation' => 'Sup3rSecret!',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_reachable_when_explicitly_allowed(): void
    {
        config([
            'installer.public_registration' => true,
            'installer.enabled' => false,
        ]);

        $this->get('/register')->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/PublicRegistrationTest.php
```

Expected: FAIL on the first test — `/register` returns 200 instead of 404.

- [ ] **Step 3: Guard registration in the middleware**

In `apps/platform/app/Http/Middleware/EnsureSelfHostedInstallation.php`, make `handle` start with the registration guard, and drop the now-redundant `$request->is('register')` check further down:

```php
    public function handle(Request $request, Closure $next): Response
    {
        // Registration is closed unless explicitly opened, whatever the installer
        // flag says. Disabling the installer must not open a public sign-up form.
        if ($request->is('register') && ! config('installer.public_registration')) {
            abort(404);
        }

        if (! $this->configuration->enabled()) {
            return $next($request);
        }

        $installerRoute = $request->routeIs('installer.*');

        if (! $this->configuration->installed()) {
            if ($installerRoute) {
                return $next($request);
            }

            if ($request->expectsJson() || ! $request->isMethod('GET')) {
                return response()->json([
                    'message' => 'This App Manager instance has not been installed yet.',
                    'install_url' => route('installer.index'),
                ], 503);
            }

            return redirect()->route('installer.index');
        }

        $this->configuration->apply();

        if ($installerRoute) {
            return redirect('/');
        }

        return $next($request);
    }
```

- [ ] **Step 4: Keep the existing registration suite meaningful**

`tests/Feature/AccountFeaturesTest.php::test_registration_requires_the_user_to_create_a_team` exercises the sign-up flow, and `phpunit.xml` already sets `SELF_HOSTED_INSTALLER_ENABLED=false`. Add to the `<php>` block of `apps/platform/phpunit.xml`, right after the `SELF_HOSTED_INSTALLER_ENABLED` line:

```xml
        <env name="ALLOW_PUBLIC_REGISTRATION" value="true"/>
```

- [ ] **Step 5: Registration tests pass, nothing else broke**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/PublicRegistrationTest.php tests/Feature/AccountFeaturesTest.php tests/Feature/SelfHostedInstallerTest.php
```

Expected: PASS.

- [ ] **Step 6: Write the failing trusted-proxy test**

Create `apps/platform/tests/Feature/TrustedProxiesTest.php`. The `Env` write happens before `parent::setUp()` because `config/app.php` is read while the application boots:

```php
<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        Env::getRepository()->set('TRUSTED_PROXIES', '*');

        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => ['secure' => $request->isSecure()]);
    }

    protected function tearDown(): void
    {
        Env::getRepository()->clear('TRUSTED_PROXIES');

        parent::tearDown();
    }

    public function test_forwarded_proto_is_honoured_when_proxies_are_trusted(): void
    {
        $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertSuccessful()
            ->assertJson(['secure' => true]);
    }
}
```

Add a sibling class in the same file directory for the negative case — create `apps/platform/tests/Feature/UntrustedProxiesTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UntrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => ['secure' => $request->isSecure()]);
    }

    public function test_forwarded_proto_is_ignored_when_no_proxy_is_trusted(): void
    {
        $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertSuccessful()
            ->assertJson(['secure' => false]);
    }
}
```

- [ ] **Step 7: Run them and watch the first fail**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/TrustedProxiesTest.php tests/Feature/UntrustedProxiesTest.php
```

Expected: `TrustedProxiesTest` FAILS (`secure` is `false`), `UntrustedProxiesTest` passes.

- [ ] **Step 8: Register trusted proxies**

In `apps/platform/config/app.php`, add after the `'url' => env('APP_URL', 'http://localhost'),` entry:

```php
    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | "*" trusts any proxy — correct when the only way in is a reverse proxy you
    | control, as in the Compose TLS profile. A comma-separated list of IPs or
    | CIDRs is also accepted. Empty means no proxy is trusted, so forwarded
    | headers are ignored.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
```

In `apps/platform/bootstrap/app.php`, inside the `withMiddleware` closure, above `$middleware->trimStrings(...)`:

```php
        $trustedProxies = trim((string) config('app.trusted_proxies'));

        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)),
            );
        }
```

- [ ] **Step 9: Both proxy tests pass**

```bash
cd apps/platform && vendor/bin/phpunit tests/Feature/TrustedProxiesTest.php tests/Feature/UntrustedProxiesTest.php
```

Expected: PASS.

- [ ] **Step 10: Document the variables**

Append to `apps/platform/.env.example`, after the bootstrap block from Task 2:

```dotenv
# Open Fortify's public /register form. Keep false on any instance reachable from the internet.
ALLOW_PUBLIC_REGISTRATION=false
# "*" or a comma-separated list of proxy IPs/CIDRs. Set only when a reverse proxy terminates TLS.
TRUSTED_PROXIES=
```

- [ ] **Step 11: Full suite, lint, commit**

```bash
cd apps/platform && composer test && vendor/bin/pint app/Http/Middleware/EnsureSelfHostedInstallation.php bootstrap/app.php config/app.php tests/Feature/PublicRegistrationTest.php tests/Feature/TrustedProxiesTest.php tests/Feature/UntrustedProxiesTest.php
cd ../.. && git add apps/platform/app/Http/Middleware/EnsureSelfHostedInstallation.php apps/platform/bootstrap/app.php apps/platform/config/app.php apps/platform/phpunit.xml apps/platform/.env.example apps/platform/tests/Feature/PublicRegistrationTest.php apps/platform/tests/Feature/TrustedProxiesTest.php apps/platform/tests/Feature/UntrustedProxiesTest.php
git commit -m "fix(security): close public registration and support trusted proxies

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The image

**Files:**
- Create: `apps/platform/Dockerfile`
- Create: `apps/platform/.dockerignore`
- Create: `apps/platform/docker/app-manager`
- Create: `apps/platform/docker/entrypoint.d/60-app-manager.sh`

**Interfaces:**
- Consumes: `app:bootstrap` (Task 2), `installer.bootstrap` semantics.
- Produces: image with entry commands `app-manager queue`, `app-manager scheduler`, `app-manager init`, `app-manager generate-key`; default command starts the web server. Reads `APP_MANAGER_BOOTSTRAP`, `APP_KEY`, `APP_BASE_DIR`.

- [ ] **Step 1: Write `.dockerignore` first**

Create `apps/platform/.dockerignore`. `.env` must be excluded — the developer's local secrets must never land in a published image:

```
.env
.env.*
!.env.example
vendor
node_modules
public/build
public/hot
public/storage
storage/app/private/*
storage/app/public/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
.git
.github
.claude
.playwright-mcp
.phpunit.result.cache
docs
pnpm-lock.yaml
```

- [ ] **Step 2: Write the role dispatcher**

Create `apps/platform/docker/app-manager`:

```sh
#!/bin/sh
# Selects which process this container runs. The base image's entrypoint has
# already executed /etc/entrypoint.d, so the environment is prepared here.
set -eu

usage() {
    echo "usage: app-manager {queue|scheduler|init|generate-key}" >&2
    exit 64
}

role="${1:-}"
[ -n "$role" ] || usage
shift

case "$role" in
    queue)
        exec php artisan queue:work \
            --queue=notifications,integrations,webhooks,default \
            --tries=3 \
            --max-time=3600 "$@"
        ;;
    scheduler)
        exec php artisan schedule:work "$@"
        ;;
    init)
        php artisan migrate --force
        php artisan app:bootstrap
        exit 0
        ;;
    generate-key)
        # Laravel's APP_KEY is 32 random bytes, base64 encoded. Generated here
        # rather than through artisan so it works before the app can boot.
        echo "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
        exit 0
        ;;
    *)
        usage
        ;;
esac
```

`init` deliberately does **not** run `config:cache` — each container has its own filesystem, so caching in `init` would help nobody. The per-container entrypoint script does it instead.

- [ ] **Step 3: Write the entrypoint script**

Create `apps/platform/docker/entrypoint.d/60-app-manager.sh`. It runs in a subshell, so it must not rely on `export` reaching the final process — the demo key is handed over through a `.env` file, which Laravel reads without overriding real environment variables:

```sh
#!/bin/sh
# Runs on every container start, before the role command. Sourced in a subshell
# by the base image's entrypoint, so a non-zero exit aborts the container start
# and exported variables do NOT reach the final process.
set -eu

APP_DIR="${APP_BASE_DIR:-/var/www/html}"
KEY_FILE="${APP_DIR}/storage/app/.app-key"
MODE="${APP_MANAGER_BOOTSTRAP:-installer}"

# `app-manager generate-key` must work on a container that has no key yet.
case "${DOCKER_CMD:-}" in
    *generate-key*) exit 0 ;;
esac

mkdir -p \
    "${APP_DIR}/storage/app/private" \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/bootstrap/cache"

if [ -z "${APP_KEY:-}" ]; then
    if [ "$MODE" != "demo" ]; then
        cat >&2 <<'MSG'
APP_KEY is not set.

Every credential in App Manager is encrypted with it: generate one, store it
with the rest of your secrets, and pass it to every container in the stack.

    docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key

Only APP_MANAGER_BOOTSTRAP=demo generates a throwaway key by itself.
MSG
        exit 1
    fi

    if [ ! -f "$KEY_FILE" ]; then
        app-manager generate-key > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi

    # Real environment variables win over .env, so this only fills the gap.
    printf 'APP_KEY=%s\n' "$(cat "$KEY_FILE")" > "${APP_DIR}/.env"
fi

# Cached per container, because bootstrap/cache is not shared between them.
# route:cache is impossible here: routes/web.php registers a closure route.
php artisan config:cache
php artisan view:cache
```

- [ ] **Step 4: Write the Dockerfile**

Create `apps/platform/Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: post-autoload-dump runs `artisan package:discover`, which boots
# the framework and would need a database and an APP_KEY that do not exist yet.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

FROM serversideup/php:8.3.32-fpm-nginx-bookworm AS app

# pdo_pgsql, redis and zip ship with the base image; gd and exif do not, and
# MobileApp::registerMediaConversions needs them for icon variants.
USER root
RUN install-php-extensions gd exif
USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

USER root
COPY docker/app-manager /usr/local/bin/app-manager
COPY docker/entrypoint.d/60-app-manager.sh /etc/entrypoint.d/60-app-manager.sh
RUN chmod +x /usr/local/bin/app-manager /etc/entrypoint.d/60-app-manager.sh
USER www-data
```

- [ ] **Step 5: Build it**

```bash
cd apps/platform && docker build -t app-manager-platform:dev .
```

Expected: build succeeds. If `npm run build` fails on a missing file, the culprit is `.dockerignore` — add the file it needs rather than loosening the `.env` rules.

- [ ] **Step 6: Verify the image's contents and its refusal to start without a key**

```bash
docker run --rm app-manager-platform:dev app-manager generate-key
docker run --rm --entrypoint php app-manager-platform:dev -m | tr '\n' ' ' | grep -o -E 'gd|exif|pdo_pgsql|redis|zip'
docker run --rm --entrypoint sh app-manager-platform:dev -c 'test -f public/build/manifest.json && echo assets-ok; test ! -f .env && echo no-dotenv-ok; ls vendor/autoload.php'
docker run --rm app-manager-platform:dev app-manager scheduler 2>&1 | head -5
```

Expected: a `base64:` key on stdout; `gd`, `exif`, `pdo_pgsql`, `redis`, `zip` all present; `assets-ok`, `no-dotenv-ok`, `vendor/autoload.php`; and the last command exits non-zero with the "APP_KEY is not set" message.

- [ ] **Step 7: Verify the demo path generates its own key**

`php artisan config:cache` and `view:cache` were confirmed to succeed with no reachable database or Redis, so the entrypoint can run them before anything else exists. This step proves the demo key round-trips through the volume:

```bash
docker volume create am-smoke-storage
docker run --rm -e APP_MANAGER_BOOTSTRAP=demo -v am-smoke-storage:/var/www/html/storage/app \
  app-manager-platform:dev php artisan --version
docker run --rm -v am-smoke-storage:/var/www/html/storage/app --entrypoint sh \
  app-manager-platform:dev -c 'cat /var/www/html/storage/app/.app-key'
docker volume rm am-smoke-storage
```

Expected: the first command runs the full entrypoint chain and prints the Laravel version (no "APP_KEY is not set" failure, because the mode is `demo`); the second prints a `base64:…` value. Note that only *named* volumes inherit the image's `www-data` ownership — a bind mount arrives root-owned and the unprivileged entrypoint cannot write to it, so anyone swapping the volume for a host path must `chown -R 33:33` it first. That belongs in the docs (Task 7).

- [ ] **Step 8: Commit**

```bash
cd /Users/igor/Projects/Personal/app-manager
git add apps/platform/Dockerfile apps/platform/.dockerignore apps/platform/docker
git commit -m "feat(docker): build the platform as a container image

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The Compose stack and its smoke test

**Files:**
- Create: `deploy/docker/compose.yaml`
- Create: `deploy/docker/.env.example`
- Create: `deploy/docker/smoke-test.sh`
- Create: `deploy/docker/README.md`

**Interfaces:**
- Consumes: the image from Task 4 (`APP_MANAGER_IMAGE`, default `ghcr.io/karerckor/app-manager-platform:latest`).
- Produces: `deploy/docker/smoke-test.sh` — exits 0 when the stack is healthy; used verbatim by the CI job in Task 6. Honours `APP_MANAGER_IMAGE` and `SMOKE_PORT` (default `8099`).

- [ ] **Step 1: Write `compose.yaml`**

```yaml
name: app-manager

x-app: &app
  image: ${APP_MANAGER_IMAGE:-ghcr.io/karerckor/app-manager-platform:latest}
  env_file: .env
  restart: unless-stopped
  volumes:
    - storage-app:/var/www/html/storage/app
  depends_on:
    init:
      condition: service_completed_successfully

services:
  postgres:
    image: postgres:17-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_DATABASE:-app_manager}
      POSTGRES_USER: ${DB_USERNAME:-app_manager}
      POSTGRES_PASSWORD: ${DB_PASSWORD:?DB_PASSWORD is required}
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-app_manager} -d ${DB_DATABASE:-app_manager}"]
      interval: 5s
      timeout: 5s
      retries: 20

  redis:
    image: redis:8-alpine
    restart: unless-stopped
    command: redis-server --appendonly yes
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 20

  # One-shot: migrations and first-run provisioning happen exactly once, not in
  # each of the three long-running containers.
  init:
    image: ${APP_MANAGER_IMAGE:-ghcr.io/karerckor/app-manager-platform:latest}
    env_file: .env
    command: ["app-manager", "init"]
    restart: "no"
    volumes:
      - storage-app:/var/www/html/storage/app
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  app:
    <<: *app
    ports:
      - "${APP_PORT:-8000}:8080"

  queue:
    <<: *app
    command: ["app-manager", "queue"]

  scheduler:
    <<: *app
    command: ["app-manager", "scheduler"]

  # docker compose --profile tls up -d
  caddy:
    profiles: [tls]
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    command:
      - caddy
      - reverse-proxy
      - --from
      - ${APP_DOMAIN:-localhost}
      - --to
      - app:8080
    volumes:
      - caddy-data:/data
      - caddy-config:/config
    depends_on:
      app:
        condition: service_started

volumes:
  postgres-data:
  redis-data:
  storage-app:
  caddy-data:
  caddy-config:
```

- [ ] **Step 2: Write `.env.example`**

```dotenv
# ==============================================================================
# Required
# ==============================================================================
# Generate once and keep it safe — every credential is encrypted with it:
#   docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key
APP_KEY=
# The URL users open. Must match the scheme they actually use.
APP_URL=http://localhost:8000
APP_PORT=8000
DB_PASSWORD=change-me

# ==============================================================================
# Baseline — sensible for a container, change only if you know why
# ==============================================================================
APP_NAME="App Manager"
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=app_manager
DB_USERNAME=app_manager
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CLIENT=phpredis
CACHE_STORE=redis
SESSION_DRIVER=database
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
MEDIA_STORAGE_DRIVER=local
LOG_CHANNEL=stderr
LOG_LEVEL=warning
# Fortify's public sign-up form. Keep it closed on anything reachable from the internet.
ALLOW_PUBLIC_REGISTRATION=false

# ==============================================================================
# First run — pick one
# ==============================================================================
# installer: create the first owner in the browser at /install (default)
# admin:     create the first owner from the ADMIN_* variables below
# demo:      seed the demo account demo@example.com / Demo123! (disposable data,
#            generates its own APP_KEY when you leave APP_KEY empty)
APP_MANAGER_BOOTSTRAP=installer
# ADMIN_NAME="Owner"
# ADMIN_EMAIL=owner@example.com
# ADMIN_PASSWORD=
# ADMIN_TEAM_NAME="My Team"

# ==============================================================================
# Credential handling
# ==============================================================================
# Which storage modes teams may use. "local" = App Manager holds the secret,
# "external" = it holds only a reference into your own vault (BYOV).
# local,external (default) | external | local
CREDENTIAL_STORAGE_MODES=local,external
# full (default) | dashboard_only — dashboard_only removes every endpoint that
# moves secret material, and suppresses credential webhooks and notifications.
CREDENTIALS_API_PROFILE=full

# ==============================================================================
# TLS — docker compose --profile tls up -d
# ==============================================================================
# APP_DOMAIN=appmanager.example.com
# TRUSTED_PROXIES=*

# ==============================================================================
# Optional — integrations, mail, S3 media storage
# ==============================================================================
# Leave these to the web installer unless you provision instances from config.
# INTEGRATIONS_ENABLED=true
# GITHUB_INTEGRATION_ENABLED=true
# GITLAB_INTEGRATION_ENABLED=true
# EXPO_INTEGRATION_ENABLED=true
# OUTBOUND_WEBHOOKS_ENABLED=true
# MAIL_MAILER=smtp
# MAIL_HOST=smtp.example.com
# MAIL_PORT=587
# MAIL_USERNAME=
# MAIL_PASSWORD=
# MAIL_FROM_ADDRESS=app-manager@example.com
# MEDIA_STORAGE_DRIVER=s3
# MEDIA_S3_ACCESS_KEY_ID=
# MEDIA_S3_SECRET_ACCESS_KEY=
# MEDIA_S3_REGION=us-east-1
# MEDIA_S3_BUCKET=
# MEDIA_S3_ENDPOINT=
# MEDIA_S3_USE_PATH_STYLE_ENDPOINT=false
```

- [ ] **Step 3: Write the smoke test**

Create `deploy/docker/smoke-test.sh` and `chmod +x` it:

```bash
#!/usr/bin/env bash
# Brings the Compose stack up in admin mode, asserts it actually works, tears it
# down. Run locally after changing the image or the stack; CI runs this file.
#
#   APP_MANAGER_IMAGE=app-manager-platform:dev ./deploy/docker/smoke-test.sh
set -euo pipefail

cd "$(dirname "$0")"

IMAGE="${APP_MANAGER_IMAGE:-ghcr.io/karerckor/app-manager-platform:latest}"
PORT="${SMOKE_PORT:-8099}"
PROJECT="am-smoke-$$"
ENV_FILE=".env"
ADMIN_EMAIL="smoke@example.com"
ADMIN_PASSWORD="Sm0keTest!Pass"

compose() {
    APP_MANAGER_IMAGE="$IMAGE" docker compose -p "$PROJECT" "$@"
}

cleanup() {
    local status=$?
    if [ $status -ne 0 ]; then
        echo "--- smoke test failed, dumping logs ---" >&2
        compose logs --no-color --tail=100 >&2 || true
    fi
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    exit $status
}
trap cleanup EXIT

if [ -e "$ENV_FILE" ]; then
    echo "refusing to overwrite an existing $PWD/$ENV_FILE" >&2
    exit 1
fi

cat > "$ENV_FILE" <<EOF
APP_KEY=$(docker run --rm "$IMAGE" app-manager generate-key)
APP_URL=http://localhost:${PORT}
APP_PORT=${PORT}
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=app_manager
DB_USERNAME=app_manager
DB_PASSWORD=smoke-secret
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CLIENT=phpredis
CACHE_STORE=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
ALLOW_PUBLIC_REGISTRATION=false
APP_MANAGER_BOOTSTRAP=admin
ADMIN_NAME=Smoke Tester
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
ADMIN_TEAM_NAME=Smoke Team
EOF

echo "==> bringing up $IMAGE"
compose up -d --wait --wait-timeout 300

echo "==> init exited cleanly"
init_exit="$(docker inspect -f '{{.State.ExitCode}}' "$(compose ps -aq init)")"
[ "$init_exit" = "0" ] || { echo "init exited with $init_exit" >&2; exit 1; }

echo "==> /up responds"
for _ in $(seq 1 60); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/up" || true)"
    [ "$code" = "200" ] && break
    sleep 2
done
[ "$code" = "200" ] || { echo "/up returned $code" >&2; exit 1; }

echo "==> admin mode provisioned the owner and closed the installer"
users="$(compose exec -T postgres psql -U app_manager -d app_manager -tAc \
    "select count(*) from users where email = '${ADMIN_EMAIL}'")"
[ "$users" = "1" ] || { echo "expected 1 admin user, found '$users'" >&2; exit 1; }

login_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/login")"
[ "$login_code" = "200" ] || { echo "/login returned $login_code" >&2; exit 1; }

install_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/install")"
[ "$install_code" = "404" ] || { echo "/install returned $install_code, expected 404" >&2; exit 1; }

register_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/register")"
[ "$register_code" = "404" ] || { echo "/register returned $register_code, expected 404" >&2; exit 1; }

echo "==> the queue worker drains a job"
compose exec -T app php artisan tinker --execute="dispatch(function () { \Illuminate\Support\Facades\Cache::put('smoke', 'ok', 300); });"
for _ in $(seq 1 30); do
    value="$(compose exec -T app php artisan tinker --execute="echo \Illuminate\Support\Facades\Cache::get('smoke', 'pending');" | tr -d '\r\n')"
    [ "$value" = "ok" ] && break
    sleep 2
done
[ "$value" = "ok" ] || { echo "queue worker did not process the job (cache value: '$value')" >&2; exit 1; }

echo "==> smoke test passed"
```

- [ ] **Step 4: Run it locally against the Task 4 image**

```bash
cd /Users/igor/Projects/Personal/app-manager && APP_MANAGER_IMAGE=app-manager-platform:dev ./deploy/docker/smoke-test.sh
```

Expected: `==> smoke test passed`. Fix the stack — not the assertions — until it does. Two failures worth predicting: `--wait` timing out because `init` is expected to exit (Compose treats a `service_completed_successfully` dependency correctly, but `--wait` on a one-shot service can be reported as unhealthy — if so, drop `init` from the waited set by running `compose up -d --wait app queue scheduler`), and `tinker --execute` needing `--no-interaction`.

- [ ] **Step 5: Verify the demo preset by hand**

```bash
cd deploy/docker && cp .env.example .env
# edit .env: APP_MANAGER_BOOTSTRAP=demo, leave APP_KEY empty, DB_PASSWORD=demo-secret
APP_MANAGER_IMAGE=app-manager-platform:dev docker compose -p am-demo up -d
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/login
APP_MANAGER_IMAGE=app-manager-platform:dev docker compose -p am-demo exec -T postgres \
    psql -U app_manager -d app_manager -tAc "select email from users"
APP_MANAGER_IMAGE=app-manager-platform:dev docker compose -p am-demo down -v
rm .env
```

Expected: `200`, and `demo@example.com` in the users table — proving the generated demo key is shared across `init` and `app`.

- [ ] **Step 6: Verify the installer preset by hand**

```bash
cd deploy/docker && cp .env.example .env
# edit .env: APP_KEY=<generated>, DB_PASSWORD=installer-secret, leave APP_MANAGER_BOOTSTRAP=installer
APP_MANAGER_IMAGE=app-manager-platform:dev docker compose -p am-installer up -d
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/install
APP_MANAGER_IMAGE=app-manager-platform:dev docker compose -p am-installer down -v
rm .env
```

Expected: `200` — the installer is reachable when no owner exists yet.

- [ ] **Step 7: Write `deploy/docker/README.md`**

```markdown
# Run App Manager with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example

# APP_KEY encrypts every credential in the instance. Generate it once, keep it.
echo "APP_KEY=$(docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key)" >> .env

docker compose up -d
```

Open <http://localhost:8000> and the installer walks you through the first owner.

The stack is `app` (web), `queue`, `scheduler`, `postgres`, `redis`, and a one-shot
`init` that runs migrations before anything else starts. `docker compose --profile
tls up -d` adds Caddy with automatic TLS for `APP_DOMAIN`.

## Startup presets

| Goal | Variables |
|---|---|
| Configure in the browser (default) | `APP_MANAGER_BOOTSTRAP=installer` → open `/install` |
| First owner straight from the environment | `APP_MANAGER_BOOTSTRAP=admin` + `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`, `ADMIN_TEAM_NAME` |
| Throwaway demo instance | `APP_MANAGER_BOOTSTRAP=demo` → `demo@example.com` / `Demo123!`, API key `demo-api-key` |
| Bring your own vault only | `CREDENTIAL_STORAGE_MODES=external` |
| Platform-held secrets only | `CREDENTIAL_STORAGE_MODES=local` |
| Both (default) | `CREDENTIAL_STORAGE_MODES=local,external` |
| Secrets never traverse the API | `CREDENTIALS_API_PROFILE=dashboard_only` |

Changing any of these is `docker compose up -d` away: the containers are
recreated and the new configuration is picked up at boot.

## Upgrading

```bash
docker compose pull && docker compose up -d
```

`init` re-runs migrations before the new containers start serving.

## Backups

Two things: the database and the `storage-app` volume.

```bash
docker compose exec -T postgres pg_dump -U app_manager app_manager | gzip > app-manager-$(date +%F).sql.gz
docker run --rm -v app-manager_storage-app:/data -v "$PWD:/backup" alpine \
    tar czf /backup/app-manager-storage-$(date +%F).tar.gz -C /data .
```

Back up `APP_KEY` with them. Without it the dump is undecryptable.

## Verifying a change

```bash
APP_MANAGER_IMAGE=app-manager-platform:dev ./smoke-test.sh
```
```

- [ ] **Step 8: Commit**

```bash
cd /Users/igor/Projects/Personal/app-manager
chmod +x deploy/docker/smoke-test.sh
git add deploy/docker
git commit -m "feat(docker): add the quick-start compose stack and its smoke test

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Publish to GHCR from GitHub Actions

**Files:**
- Create: `.github/workflows/platform-image.yml`

**Interfaces:**
- Consumes: `apps/platform/Dockerfile`, `deploy/docker/smoke-test.sh`.
- Produces: `ghcr.io/karerckor/app-manager-platform` tagged `edge` + `sha-<short>` on `main`, and `X.Y.Z` + `X.Y` + `latest` on `v*` tags.

- [ ] **Step 1: Write the workflow**

```yaml
name: Platform Image

on:
  pull_request:
    paths:
      - apps/platform/**
      - deploy/docker/**
      - .github/workflows/platform-image.yml
  push:
    branches:
      - main
    paths:
      - apps/platform/**
      - deploy/docker/**
      - .github/workflows/platform-image.yml
    tags:
      - 'v*'

concurrency:
  group: platform-image-${{ github.head_ref || github.ref_name }}
  cancel-in-progress: true

env:
  IMAGE_NAME: ghcr.io/${{ github.repository_owner }}/app-manager-platform

jobs:
  build:
    name: Build ${{ matrix.platform }}
    runs-on: ${{ matrix.runner }}
    permissions:
      contents: read
      packages: write
    strategy:
      fail-fast: false
      matrix:
        include:
          # Native runners for both architectures: an emulated composer install
          # plus vite build costs minutes for nothing. ubuntu-24.04-arm is free
          # for public repositories only.
          - platform: linux/amd64
            runner: ubuntu-latest
          - platform: linux/arm64
            runner: ubuntu-24.04-arm

    steps:
      - uses: actions/checkout@v6

      - name: Set up Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to GHCR
        if: github.event_name != 'pull_request'
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build
        id: build
        uses: docker/build-push-action@v6
        with:
          context: apps/platform
          file: apps/platform/Dockerfile
          platforms: ${{ matrix.platform }}
          cache-from: type=gha,scope=${{ matrix.platform }}
          cache-to: type=gha,mode=max,scope=${{ matrix.platform }}
          outputs: ${{ github.event_name == 'pull_request' && 'type=cacheonly' || format('type=image,name={0},push-by-digest=true,name-canonical=true,push=true', env.IMAGE_NAME) }}

      - name: Export digest
        if: github.event_name != 'pull_request'
        run: |
          mkdir -p /tmp/digests
          digest="${{ steps.build.outputs.digest }}"
          touch "/tmp/digests/${digest#sha256:}"

      - name: Upload digest
        if: github.event_name != 'pull_request'
        uses: actions/upload-artifact@v5
        with:
          name: digest-${{ strategy.job-index }}
          path: /tmp/digests/*
          if-no-files-found: error
          retention-days: 1

  manifest:
    name: Publish manifest
    runs-on: ubuntu-latest
    needs: build
    if: github.event_name != 'pull_request'
    permissions:
      contents: read
      packages: write

    steps:
      - name: Download digests
        uses: actions/download-artifact@v5
        with:
          path: /tmp/digests
          pattern: digest-*
          merge-multiple: true

      - name: Set up Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Compute tags
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.IMAGE_NAME }}
          tags: |
            type=edge,branch=main
            type=sha,prefix=sha-
            type=semver,pattern={{version}}
            type=semver,pattern={{major}}.{{minor}}
            type=raw,value=latest,enable=${{ startsWith(github.ref, 'refs/tags/v') }}

      - name: Create the manifest list
        working-directory: /tmp/digests
        run: |
          docker buildx imagetools create \
            $(jq -cr '.tags | map("-t " + .) | join(" ")' <<< "$DOCKER_METADATA_OUTPUT_JSON") \
            $(printf '${{ env.IMAGE_NAME }}@sha256:%s ' *)

      - name: Inspect
        run: docker buildx imagetools inspect ${{ env.IMAGE_NAME }}:${{ steps.meta.outputs.version }}

  smoke:
    name: Smoke test the stack
    runs-on: ubuntu-latest
    needs: build

    steps:
      - uses: actions/checkout@v6

      - name: Set up Buildx
        uses: docker/setup-buildx-action@v3

      - name: Load the amd64 image
        uses: docker/build-push-action@v6
        with:
          context: apps/platform
          file: apps/platform/Dockerfile
          platforms: linux/amd64
          load: true
          tags: app-manager-platform:smoke
          cache-from: type=gha,scope=linux/amd64

      - name: Run the stack smoke test
        env:
          APP_MANAGER_IMAGE: app-manager-platform:smoke
        run: ./deploy/docker/smoke-test.sh
```

- [ ] **Step 2: Validate the YAML**

```bash
cd /Users/igor/Projects/Personal/app-manager
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/platform-image.yml')); print('yaml ok')"
```

Expected: `yaml ok`. If `actionlint` is available (`brew install actionlint`), run `actionlint .github/workflows/platform-image.yml` too and fix what it reports.

- [ ] **Step 3: Confirm the smoke script the workflow calls still passes locally**

```bash
APP_MANAGER_IMAGE=app-manager-platform:dev ./deploy/docker/smoke-test.sh
```

Expected: `==> smoke test passed`. This is the same entry point CI uses, so a green run here is meaningful evidence.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/platform-image.yml
git commit -m "ci(docker): build and publish the platform image to GHCR

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 5: Verify on a real run**

Push the branch and open the PR. On the PR run, confirm: both `build` jobs succeed, `smoke` succeeds, and `manifest` is skipped. After merge to `main`, confirm `manifest` publishes and `docker pull ghcr.io/karerckor/app-manager-platform:edge` works on this machine (arm64), which proves the manifest list resolves per architecture:

```bash
docker pull ghcr.io/karerckor/app-manager-platform:edge
docker buildx imagetools inspect ghcr.io/karerckor/app-manager-platform:edge
```

Expected: both `linux/amd64` and `linux/arm64` in the inspect output. Note: the package is private until made public in the repository's package settings — say so in the PR description so it gets flipped.

---

### Task 7: Documentation

**Files:**
- Create: `apps/docs/src/pages/self-hosting.astro`
- Modify: `apps/docs/src/layouts/DocsLayout.astro` (the `navSections` array at line 14)
- Modify: `README.md`
- Modify: `apps/platform/README.md`

**Interfaces:**
- Consumes: `deploy/docker/README.md` content from Task 5 — the same preset table, one canonical wording.

- [ ] **Step 1: Add the nav entry**

In `apps/docs/src/layouts/DocsLayout.astro`, in the `Overview` section's `items`, after `{ label: 'Getting Started', href: '/getting-started' }`:

```js
      { label: 'Self-hosting', href: '/self-hosting' },
```

- [ ] **Step 2: Write the page**

Create `apps/docs/src/pages/self-hosting.astro`, following the conventions of the existing pages (`DocsLayout` with `activeNav`, `CodeBlock` for every command block):

```astro
---
import DocsLayout from '../layouts/DocsLayout.astro';
import CodeBlock from '../components/CodeBlock.astro';
---

<DocsLayout title="Self-hosting" activeNav="/self-hosting">
  <h1>Self-hosting</h1>
  <p>
    App Manager ships as a container image on GitHub Container Registry, plus a Compose stack that
    runs the web console, the queue worker, the scheduler, PostgreSQL and Redis. Everything the
    instance does — how credentials may be stored, whether secrets traverse the API, how the first
    owner comes into existence — is set through environment variables.
  </p>

  <h2>Quick start</h2>
  <CodeBlock lang="bash" code={`mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example

echo "APP_KEY=$(docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key)" >> .env

docker compose up -d`} />
  <p>
    Open <code>http://localhost:8000</code>. The installer creates your first owner and team.
  </p>
  <p>
    <strong><code>APP_KEY</code> is not a formality.</strong> Every credential in the vault is
    encrypted with it. Store it wherever you keep production secrets and back it up with the
    database — a dump without the key cannot be decrypted.
  </p>

  <h2>What runs</h2>
  <table>
    <thead>
      <tr><th>Service</th><th>Role</th></tr>
    </thead>
    <tbody>
      <tr><td><code>init</code></td><td>One-shot: runs migrations and the first-run bootstrap, then exits. Everything else waits for it.</td></tr>
      <tr><td><code>app</code></td><td>nginx + PHP-FPM serving the console and the REST API.</td></tr>
      <tr><td><code>queue</code></td><td>Worker for the <code>notifications</code>, <code>integrations</code>, <code>webhooks</code> and <code>default</code> queues.</td></tr>
      <tr><td><code>scheduler</code></td><td>Build reconciliation, log archiving, credential expiry checks.</td></tr>
      <tr><td><code>postgres</code>, <code>redis</code></td><td>Database and cache. Point the stack at managed instances by changing <code>DB_HOST</code> / <code>REDIS_HOST</code> and removing these services.</td></tr>
      <tr><td><code>caddy</code></td><td>Optional. <code>docker compose --profile tls up -d</code> terminates TLS for <code>APP_DOMAIN</code>.</td></tr>
    </tbody>
  </table>

  <h2>Startup presets</h2>
  <table>
    <thead>
      <tr><th>Goal</th><th>Variables</th></tr>
    </thead>
    <tbody>
      <tr><td>Configure in the browser (default)</td><td><code>APP_MANAGER_BOOTSTRAP=installer</code></td></tr>
      <tr><td>First owner straight from the environment</td><td><code>APP_MANAGER_BOOTSTRAP=admin</code> with <code>ADMIN_EMAIL</code>, <code>ADMIN_PASSWORD</code>, <code>ADMIN_NAME</code>, <code>ADMIN_TEAM_NAME</code></td></tr>
      <tr><td>Throwaway demo instance</td><td><code>APP_MANAGER_BOOTSTRAP=demo</code> — seeds <code>demo@example.com</code> / <code>Demo123!</code> and the <code>demo-api-key</code> API key</td></tr>
      <tr><td>Bring your own vault only</td><td><code>CREDENTIAL_STORAGE_MODES=external</code></td></tr>
      <tr><td>Platform-held secrets only</td><td><code>CREDENTIAL_STORAGE_MODES=local</code></td></tr>
      <tr><td>Secrets never traverse the API</td><td><code>CREDENTIALS_API_PROFILE=dashboard_only</code></td></tr>
    </tbody>
  </table>
  <p>
    Only <code>demo</code> generates its own <code>APP_KEY</code>; the data is disposable, so a key
    that disappears with the volume costs nothing. In the other modes the container refuses to start
    without one, on purpose.
  </p>

  <h2>Environment reference</h2>
  <table>
    <thead>
      <tr><th>Variable</th><th>Default</th><th>What it does</th></tr>
    </thead>
    <tbody>
      <tr><td><code>APP_KEY</code></td><td>—</td><td>Required. Encrypts credentials, sessions and stored integration secrets.</td></tr>
      <tr><td><code>APP_URL</code></td><td><code>http://localhost:8000</code></td><td>Public URL. Must match the scheme users actually use.</td></tr>
      <tr><td><code>APP_PORT</code></td><td><code>8000</code></td><td>Host port for the console.</td></tr>
      <tr><td><code>APP_MANAGER_BOOTSTRAP</code></td><td><code>installer</code></td><td><code>installer</code>, <code>admin</code> or <code>demo</code>. Anything but <code>installer</code> disables the web installer.</td></tr>
      <tr><td><code>CREDENTIAL_STORAGE_MODES</code></td><td><code>local,external</code></td><td>Instance-wide ceiling on credential storage modes. A team may narrow it, never widen it.</td></tr>
      <tr><td><code>CREDENTIALS_API_PROFILE</code></td><td><code>full</code></td><td><code>dashboard_only</code> removes every endpoint that moves secret material.</td></tr>
      <tr><td><code>ALLOW_PUBLIC_REGISTRATION</code></td><td><code>false</code></td><td>Opens the public sign-up form. Leave closed on anything internet-facing.</td></tr>
      <tr><td><code>TRUSTED_PROXIES</code></td><td>empty</td><td><code>*</code> or a comma-separated list. Set only when a reverse proxy terminates TLS.</td></tr>
      <tr><td><code>MEDIA_STORAGE_DRIVER</code></td><td><code>local</code></td><td><code>local</code> keeps files in the <code>storage-app</code> volume; <code>s3</code> uses the <code>MEDIA_S3_*</code> variables.</td></tr>
      <tr><td><code>DB_HOST</code>, <code>REDIS_HOST</code></td><td><code>postgres</code>, <code>redis</code></td><td>Point at managed services to drop the bundled containers.</td></tr>
    </tbody>
  </table>

  <h2>Upgrading</h2>
  <CodeBlock lang="bash" code={`docker compose pull && docker compose up -d`} />
  <p>
    <code>init</code> re-runs migrations before the new containers start serving. Pin a version
    instead of <code>latest</code> — <code>ghcr.io/karerckor/app-manager-platform:1.4</code> — if you
    want upgrades to be a deliberate edit.
  </p>

  <h2>Backups</h2>
  <CodeBlock lang="bash" code={`docker compose exec -T postgres pg_dump -U app_manager app_manager | gzip > app-manager-$(date +%F).sql.gz
docker run --rm -v app-manager_storage-app:/data -v "$PWD:/backup" alpine \\
  tar czf /backup/app-manager-storage-$(date +%F).tar.gz -C /data .`} />
  <p>
    The database holds everything except uploaded files; the <code>storage-app</code> volume holds
    keystores, certificates and build artifacts when <code>MEDIA_STORAGE_DRIVER=local</code>. Keep
    <code>APP_KEY</code> alongside both.
  </p>
  <p>
    If you replace that named volume with a host directory, <code>chown -R 33:33</code> it first. The
    container runs unprivileged as <code>www-data</code> (uid 33) and cannot take ownership of a
    root-owned bind mount; named volumes inherit the right ownership from the image automatically.
  </p>
</DocsLayout>
```

- [ ] **Step 3: Build the docs site**

```bash
cd apps/docs && npm run build
```

Expected: build succeeds and mentions `self-hosting` among the built pages. If `pnpm` is the package manager here (`pnpm-lock.yaml` is present), use `pnpm build`.

- [ ] **Step 4: Add the README sections**

In the root `README.md`, above the existing local-development instructions, add:

```markdown
## Run with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example
echo "APP_KEY=$(docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key)" >> .env
docker compose up -d
```

Open <http://localhost:8000>. See [`deploy/docker`](deploy/docker/README.md) for the startup
presets — installer, env-provided admin, or demo data — and the credential storage modes.
```

In `apps/platform/README.md`, add the same block right after the `# App Manager Platform` intro paragraph, before `## Requirements`, and note that the section below is for developing the platform rather than running it.

- [ ] **Step 5: Commit**

```bash
cd /Users/igor/Projects/Personal/app-manager
git add apps/docs/src/pages/self-hosting.astro apps/docs/src/layouts/DocsLayout.astro README.md apps/platform/README.md
git commit -m "docs: document self-hosting with Docker

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Final verification

- [ ] `cd apps/platform && composer test` — full PHP suite green.
- [ ] `cd apps/platform && vendor/bin/pint --test` — clean.
- [ ] `cd apps/platform && npm run verify` — frontend unaffected.
- [ ] `cd apps/docs && npm run build` — docs build.
- [ ] `APP_MANAGER_IMAGE=app-manager-platform:dev ./deploy/docker/smoke-test.sh` — stack green.
- [ ] `git status` — no stray `deploy/docker/.env`, no `app-manager-*.sql.gz`, no leftover volumes (`docker volume ls | grep am-`).
