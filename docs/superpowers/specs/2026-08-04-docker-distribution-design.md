# Docker distribution: GHCR image + quick-start compose

**Date:** 2026-08-04

Ship the platform as a container image published to GitHub Container Registry, plus an example Compose stack that brings up a working App Manager — Postgres, Redis, queue worker, scheduler — in two commands. The stack is configured entirely through environment variables, including the credential storage modes, the `dashboard-only` API profile, and how the first owner comes into existence.

## Why

Today the only documented way to run the platform is a developer checkout: PHP 8.3, Composer, Node 22, npm build, `composer run dev`. That is fine for contributors and hostile to the actual audience — a mobile team that wants a self-hosted control plane for its signing credentials and does not want to become a Laravel shop to get one.

A published image also pins the runtime we support. The app needs specific extensions (`pgsql`, `redis`, `zip`, `gd`/`exif` for icon conversions) and a specific process set (web + queue + scheduler); today all of that lives in prose in the README and in `composer run dev`. In an image it is executable and testable.

## Scope

- `apps/platform/Dockerfile` — multi-stage build of one image serving four roles.
- `.github/workflows/platform-image.yml` — build, smoke-test, publish multi-arch to GHCR.
- `deploy/docker/compose.yaml` + `deploy/docker/.env.example` — the quick-start stack.
- `php artisan app:bootstrap` — the first-run modes.
- Two safety fixes the container modes expose: public registration and trusted proxies.
- Docs: a Self-hosting page on the docs site, plus README sections.

Out of scope: Kubernetes manifests, Helm charts, hosted-distribution deploys, and any change to the existing `platform-ci.yml` / `platform-release.yml` workflows.

---

## The image

**Location:** `apps/platform/Dockerfile`. Build context is `apps/platform` — nothing outside it is needed.

**Base:** `serversideup/php:8.3-fpm-nginx`. One container serves HTTP through nginx + PHP-FPM under s6, with stdout logging and a non-root `www-data` runtime already solved. Classic FPM, not a long-running worker: no shared state between requests, no surprises for self-hosted operators.

### Stages

| Stage | Base | Produces |
|---|---|---|
| `vendor` | `composer:2` | `vendor/` from `composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader`, then app sources copied in and `composer dump-autoload --optimize --no-dev` |
| `assets` | `node:22-alpine` | `public/build` from `npm ci --ignore-scripts && npm run build` |
| final | `serversideup/php:8.3-fpm-nginx` | app sources + `vendor/` + `public/build` |

Extensions installed in the final stage: `pgsql`, `pdo_pgsql`, `redis`, `zip`, `gd`, `exif`, `intl`. `gd` and `exif` are required — `MobileApp::registerMediaConversions` generates icon variants through MediaLibrary. `zip` is required by `GitHubProvider`, which explicitly checks for `ZipArchive` before downloading build logs. No JRE: keystore and `.p12` validation in `App\Rules\ValidCredentialFile` is pure `openssl_*`, nothing shells out to `keytool`.

Build-stage `composer install` runs with `--no-scripts` because `post-autoload-dump` calls `artisan package:discover`, which boots the framework and would need a database and an `APP_KEY` that do not exist at build time.

`APP_ENV=production` and opcache on by default. `AUTORUN_ENABLED=false` — every start-up step is an explicit line in our own entrypoint script rather than an inherited environment-variable contract from the base image.

### Roles

The image ships an `app-manager` entry command that selects the role, so one image covers the whole stack:

| Command | Process |
|---|---|
| (default) | nginx + PHP-FPM |
| `app-manager queue` | `php artisan queue:work --queue=default --tries=3 --max-time=3600` |
| `app-manager scheduler` | `php artisan schedule:work` |
| `app-manager init` | one-shot: `migrate --force` → `app:bootstrap`, then exit 0 |
| `app-manager generate-key` | prints a fresh `base64:` key to stdout and exits — no side effects |

The queue list mirrors `composer run dev` and covers `MEDIA_QUEUE=default`.

**Migrations run only in `init`.** `app`, `queue` and `scheduler` never migrate. Three containers racing `migrate --force` on first boot is a real failure mode, and the installer's own requirement check demands migrations be complete before its screen renders.

### Entrypoint responsibilities

A single script in the base image's `/etc/entrypoint.d`, run before the role command:

1. Ensure `storage/framework/{cache,sessions,views}`, `storage/logs` and `bootstrap/cache` exist.
2. Resolve `APP_KEY`:
   - present in the environment → use it;
   - absent and `APP_MANAGER_BOOTSTRAP=demo` → read `storage/app/.app-key` if it exists, otherwise generate one and write it `0600`, then hand it to the app by writing `APP_KEY=…` into `.env` (real environment variables outrank `.env`, so this only fills the gap);
   - absent in any other mode → exit non-zero with the exact command to fix it (`docker run --rm <image> app-manager generate-key`).
3. Run `config:cache` and `view:cache`.
4. Exec the role command.

The handover through `.env` rather than `export` is forced by the base image: it *sources each entrypoint script in a subshell*, so exported variables never reach the final process. A non-zero exit does abort the container start, which is what makes the missing-key check a hard stop.

Config and view caching happen per container, not in `init`: `bootstrap/cache` lives inside each container's own filesystem, so caching once in `init` would benefit nobody. **Route caching is impossible** — `routes/web.php` registers a closure route, and `artisan route:cache` refuses to serialise closures.

The demo key lives in the shared `storage/app` volume, so `init` creates it and the other containers read the same value — they start only after `init` exits, so there is no race. Confining generated keys to `demo` is deliberate: an auto-generated key that disappears with `docker compose down -v` turns every encrypted credential in the database into garbage. Demo data is disposable, so there the convenience is free.

### Volumes

One named volume at `/var/www/html/storage/app`. That path holds the local media root (`media-storage.local.root` defaults to `storage_path('app/private')`) — which is where locally-stored keystores, `.p12` files and build artifacts live — plus the demo key file. `storage/framework` and `storage/logs` stay inside the container; logs go to stdout.

---

## Publishing workflow

**File:** `.github/workflows/platform-image.yml`. **Image:** `ghcr.io/karerckor/app-manager-platform`.

Triggers, all filtered to `apps/platform/**`, `deploy/docker/**` and the workflow file itself:

| Event | Tags pushed |
|---|---|
| `pull_request` | none — build amd64 only, then smoke-test |
| `push` to `main` | `edge`, `sha-<short>` |
| `push` tag `v*` | `1.2.3`, `1.2`, `latest` |

### Jobs

1. **`build`** — matrix of two native runners: `ubuntu-latest` (linux/amd64) and `ubuntu-24.04-arm` (linux/arm64). No QEMU; an emulated `composer install` plus `npm run build` is minutes of wasted CI. Each job builds and, on non-PR events, pushes by digest with `outputs: type=image,push-by-digest=true,name-canonical=true`, exporting its digest as an artifact. Cache is `type=gha` with a per-architecture scope.
2. **`manifest`** — needs `build`; `docker buildx imagetools create` merges the two digests into one manifest list with the tags computed by `docker/metadata-action`. `docker pull` then resolves the right architecture automatically, and per-architecture digests remain addressable for anyone who wants to pin one.
3. **`smoke`** — needs `build`, runs on every event including PRs. Rebuilds amd64 with `--load` off the shared GHA cache (cheap — same cache the build job just filled), then:
   - `docker compose -f deploy/docker/compose.yaml up -d` with `APP_MANAGER_IMAGE` pointing at the local tag, `APP_MANAGER_BOOTSTRAP=admin` and test `ADMIN_*` values;
   - assert `init` exited 0;
   - poll `/up` until healthy (bounded, then fail with `docker compose logs`);
   - log in over HTTP as the configured admin and assert a redirect to the dashboard, not to `/install`;
   - dispatch a trivial queued job through `artisan` and assert the worker drains it;
   - tear down with `down -v`.

Permissions: `contents: read`, `packages: write`; registry login with `GITHUB_TOKEN`. `platform-ci.yml` and `platform-release.yml` are untouched — image publishing is an independent workflow keyed on the same tags.

---

## The Compose stack

**Files:** `deploy/docker/compose.yaml`, `deploy/docker/.env.example`. The repository-root `docker-compose.yml` keeps its current job — Postgres + Redis for contributors — and is not modified.

The Compose file references `${APP_MANAGER_IMAGE:-ghcr.io/karerckor/app-manager-platform:latest}` and builds nothing, so it works when downloaded on its own next to a `.env`.

| Service | Notes |
|---|---|
| `postgres` | `postgres:17-alpine`, `pg_isready` healthcheck, named volume, no published port by default |
| `redis` | `redis:8-alpine`, `--appendonly yes`, `redis-cli ping` healthcheck, named volume |
| `init` | `app-manager init`, `restart: "no"`, depends on both datastores being healthy |
| `app` | default command, `${APP_PORT:-8000}` → the base image's HTTP port (`8080` in `serversideup/php` images — confirm against the pinned tag), healthcheck on `/up` |
| `queue` | `app-manager queue` |
| `scheduler` | `app-manager scheduler` |
| `caddy` | `profiles: [tls]` — reverse proxy with automatic TLS for `APP_DOMAIN`, volumes for its data and config |

`app`, `queue` and `scheduler` all declare `depends_on: init: condition: service_completed_successfully`. Datastore credentials, Redis host and app environment come from the shared `.env`; every service reads the same `env_file` so a mode change is one edit in one place.

Without the `tls` profile the stack publishes a plain HTTP port on the host and TLS is the operator's problem — documented, not pretended away. With it, Caddy terminates TLS and sets `TRUSTED_PROXIES`.

### Defaults in `.env.example`

Required block first (`APP_KEY`, `APP_URL`, `APP_PORT`, `POSTGRES_PASSWORD`/`DB_PASSWORD`), then commented blocks for bootstrap, credential modes, integrations, mail, S3 media storage, and TLS. Each block carries a one-line comment explaining what changes when it is uncommented.

Baseline values: `APP_ENV=production`, `APP_DEBUG=false`, `DB_HOST=postgres`, `REDIS_HOST=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `QUEUE_CONNECTION=database`, `MEDIA_STORAGE_DRIVER=local`, `ALLOW_PUBLIC_REGISTRATION=false`, `TRUSTED_PROXIES=` (empty). The queue stays on the database connection: it is durable, needs no eviction policy tuning, and the throughput of a credentials control plane does not justify anything else.

### Mode presets

One documented table, in `deploy/docker/README.md` and on the docs page:

| Goal | Variables |
|---|---|
| Configure through the browser (default) | `APP_MANAGER_BOOTSTRAP=installer` → open `/install` |
| First owner straight from env | `APP_MANAGER_BOOTSTRAP=admin` + `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`, `ADMIN_TEAM_NAME` |
| Demo instance | `APP_MANAGER_BOOTSTRAP=demo` → `demo@example.com` / `Demo123!`, API key `demo-api-key` |
| Bring your own vault only | `CREDENTIAL_STORAGE_MODES=external` |
| Platform-held secrets only | `CREDENTIAL_STORAGE_MODES=local` |
| Both storage modes (default) | `CREDENTIAL_STORAGE_MODES=local,external` |
| Dashboard-only — secrets never traverse the API | `CREDENTIALS_API_PROFILE=dashboard_only` |

The credential variables already exist in `config/credentials.php`; this feature only surfaces and documents them. `CREDENTIALS_API_PROFILE` is resolved at boot, so changing it means recreating the containers — which is exactly what `docker compose up -d` does.

---

## `php artisan app:bootstrap`

**New:** `app/Console/Commands/BootstrapInstance.php`. **New config key:** `installer.bootstrap` ← `env('APP_MANAGER_BOOTSTRAP', 'installer')`.

Signature: `app:bootstrap {--mode=}`. The option overrides the config value; an unrecognised mode fails with the list of valid modes. The command is idempotent — running it on every container start is expected.

| Mode | Behaviour |
|---|---|
| `installer` | No writes. Prints the installer URL. |
| `admin` | Requires `ADMIN_EMAIL` and `ADMIN_PASSWORD`; missing either is a hard failure naming the variable. If any user already exists, logs "already provisioned" and exits 0. Otherwise provisions the first owner. |
| `demo` | If any user already exists, exits 0. Otherwise calls `DemoSeeder` directly. |

`demo` calls the seeder class directly rather than `db:seed`, because `DatabaseSeeder` refuses to seed outside `local`/`development`/`testing` and the container runs `APP_ENV=production`. `DemoSeeder` is already documented as idempotent.

`admin` and `demo` must not leave the web installer reachable, and requiring the operator to remember a second variable is a footgun. So `config/installer.php` gains one condition: the installer is enabled only when the bootstrap mode *is* `installer`.

```php
'enabled' => $selfHosted
    && env('SELF_HOSTED_INSTALLER_ENABLED', true)
    && env('APP_MANAGER_BOOTSTRAP', 'installer') === 'installer',
```

Existing deployments do not set `APP_MANAGER_BOOTSTRAP`, so the default keeps their behaviour exactly. With the installer off, `SelfHostedConfiguration::apply()` is a no-op and every setting comes from the environment — the behaviour we want in a container: no database-stored config silently overriding `docker-compose.yaml`.

### Extracted action

`InstallerController::store` currently inlines the first-owner transaction: create user through `CreateNewUser`, create team, add owner membership, record the membership audit, set `current_team_id`, insert the `installations` row. `app:bootstrap admin` needs exactly that.

Move it to `App\Actions\Installation\ProvisionFirstOwner`, invoked by both. The action takes the owner name, email, password and team name, plus an optional instance name and settings array, and returns the `User`. `InstallerController` keeps its lock, its validation, its storage connection test and its `Auth::login`.

The `installations` row is written only when the instance name and settings are supplied — the installer supplies them, `app:bootstrap admin` does not. A container's configuration comes from its environment; writing a settings row would let a database value silently override `docker-compose.yaml` if the installer were ever re-enabled. Skipping it costs nothing, because `SelfHostedConfiguration::installed()` already returns true once the users table is populated. One code path still creates the owner, so the audit row and `current_team_id` cannot drift between the two entry points.

---

## Two safety fixes the container modes expose

Both are small, both are required for the modes above to be safe, and both come with tests.

### 1. Public registration

Fortify has `Features::registration()` enabled. `EnsureSelfHostedInstallation` aborts `/register` with 404 — but only when the installer is *enabled and installed*. The `admin` and `demo` modes disable the installer, so the middleware returns early and Fortify's registration routes become reachable on a publicly exposed instance: anyone can create an account.

Add `installer.public_registration` ← `env('ALLOW_PUBLIC_REGISTRATION', false)` and block the registration routes in `EnsureSelfHostedInstallation` whenever it is false, independent of the installer flag. Default `false` preserves today's behaviour for every existing deployment; the only case that changes is "installer disabled, no users yet", which is exactly the hole.

### 2. Trusted proxies

`bootstrap/app.php` does not configure `trustProxies`. Behind Caddy the app sees plain HTTP, generates `http://` URLs against an `https://` `APP_URL`, and secure cookies misbehave.

Add `$middleware->trustProxies(at: env('TRUSTED_PROXIES'))` with a null default — trust nobody unless told. The `tls` profile sets `TRUSTED_PROXIES` for the proxy network, and the docs explain why you set it only when something in front of the app terminates TLS.

---

## Testing

**PHPUnit (`apps/platform/tests`)**

- `app:bootstrap` in `installer` mode writes nothing.
- `admin` mode creates the owner, the team, the owner membership, the audit row, and `current_team_id`.
- `admin` mode is idempotent — a second run with an existing user changes nothing and exits 0.
- `admin` mode fails when `ADMIN_EMAIL` or `ADMIN_PASSWORD` is missing, and the message names the variable.
- `demo` mode seeds the demo account; a second run changes nothing.
- An unknown mode fails with a non-zero exit code.
- The installer still provisions the first owner end to end through the extracted action (existing installer tests must keep passing).
- `/register` returns 404 when `ALLOW_PUBLIC_REGISTRATION=false` and the installer is disabled; it is reachable when the flag is true.

**CI smoke test** — covers what PHPUnit cannot: that the image boots, that `init` ordering works, that the worker drains a job, and that an `admin`-mode stack lands on the dashboard rather than `/install`.

---

## Documentation

- **New page** `apps/docs/src/pages/platform/self-hosting.astro` — quick start (`curl` the compose file, generate a key, `up -d`), the mode preset table, the full environment-variable reference, upgrading (`docker compose pull && up -d`, `init` re-runs migrations), backups (`pg_dump` plus the `storage/app` volume), and a warning that losing `APP_KEY` means losing every locally-stored secret.
- **`deploy/docker/README.md`** — the same quick start and preset table, kept short, for people who land in the directory from GitHub.
- **Root `README.md` and `apps/platform/README.md`** — a "Run with Docker" section pointing at `deploy/docker`, above the existing developer-checkout instructions.

## Risks

- **`serversideup/php` tag drift.** Pin the base image to `serversideup/php:8.3.32-fpm-nginx-bookworm` — patch and distro pinned — so a base rebuild cannot change our runtime silently.
- **`ubuntu-24.04-arm` runner availability.** Free for public repositories; if the repository goes private the arm64 job needs a paid runner or a QEMU fallback. Documented in the workflow as a comment rather than discovered during an outage.
- **Volume ownership.** The base image runs unprivileged as `www-data` (uid 33), so it cannot take ownership of a root-owned mount. A fresh *named* volume inherits the image's ownership and needs nothing; a *bind mount* arrives root-owned and must be `chown -R 33:33`'d by the operator. The stack therefore uses named volumes, and the docs say this explicitly for anyone who swaps in a host path.
