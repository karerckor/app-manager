# Run App Manager with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example

# PostgreSQL refuses to start with the blank value from .env.example.
echo "DB_PASSWORD=$(openssl rand -hex 32)" >> .env
# APP_KEY encrypts every credential in the instance. Generate it once, keep it.
echo "APP_KEY=$(docker run --rm --entrypoint app-manager ghcr.io/karerckor/app-manager-platform:latest generate-key)" >> .env

docker compose up -d
```

Open <http://localhost:8000> and the installer walks you through the first owner.

The stack is `app` (web), `queue`, `scheduler`, `postgres`, `redis`, and a one-shot
`init` that runs before anything else starts: migrations, then `encryption:migrate`
to make sure the instance has an active credential encryption key, then the
first-run bootstrap. `docker compose --profile tls up -d` adds Caddy with
automatic TLS for `APP_DOMAIN`.

## Startup presets

| Goal | Variables |
|---|---|
| Configure in the browser (default) | `APP_MANAGER_BOOTSTRAP=installer` → open `/install` |
| First owner straight from the environment | `APP_MANAGER_BOOTSTRAP=admin` + `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`, `ADMIN_TEAM_NAME` |
| Throwaway demo instance | `APP_MANAGER_BOOTSTRAP=demo` **and** `APP_ENV=local` → `demo@example.com` / `Demo123!`, API key `demo-api-key` |
| Bring your own vault only | `CREDENTIAL_STORAGE_MODES=external` |
| Platform-held secrets only | `CREDENTIAL_STORAGE_MODES=local` |
| Both (default) | `CREDENTIAL_STORAGE_MODES=local,external` |
| Secrets never traverse the API | `CREDENTIALS_API_PROFILE=dashboard_only` |

Changing any of these is `docker compose up -d` away: the containers are
recreated and the new configuration is picked up at boot.

Demo mode is the one mode that does not need an `APP_KEY`: if you haven't set
one, the container generates a throwaway key on first boot and keeps it in the
`storage-app` volume, so restarts and upgrades reuse it. The key reaches the
application through the config cache that the container's entrypoint builds at
startup, so if you clear that cache inside a running container
(`docker compose exec app php artisan config:clear`), restart the container
afterwards (`docker compose restart app queue scheduler`) rather than leaving it
running. Every other mode refuses to start without `APP_KEY`, on purpose.

## TLS

```bash
docker compose --profile tls up -d
```

Caddy obtains a certificate for `APP_DOMAIN` and proxies to `app:8080`. Uncomment
the whole TLS block in `.env`, not only `APP_DOMAIN`:

- `APP_URL=https://your-domain` — a stale `http://localhost:8000` breaks signed
  URLs, mail links, and the GitHub App redirect URI.
- `APP_PORT=127.0.0.1:8000` — keeps the plaintext port off the network.
- `SESSION_SECURE_COOKIE=true`.
- `TRUSTED_PROXIES=*`.

`TRUSTED_PROXIES=*` makes App Manager believe the `X-Forwarded-For` and
`X-Forwarded-Proto` of whoever connected, so it is only acceptable while Caddy is
the only thing that can reach the app — which is exactly what binding the
plaintext port to loopback buys you. With that port on `0.0.0.0`, a client can
choose the IP that App Manager rate-limits logins by and records as the actor in
the credential-access audit log.

`queue` and `scheduler` use process healthchecks. Confirm both are healthy with
`docker compose ps`; use `docker compose logs queue scheduler` when either
process is restarting or unhealthy.

## Data retention and recovery

Applications, credentials, identifiers, profiles, builds, artifacts, blueprints,
teams, and users go to a recoverable trash first. The scheduler permanently
purges them no earlier than `TRASH_RETENTION_DAYS` (90 by default); credentials
can use a separate `CREDENTIAL_TRASH_RETENTION_DAYS` window. A parent stays in
trash longer when deleting it would cut short a child build-log or Sentry
archive's retention window.

Completed build logs stay in PostgreSQL for `BUILD_LOG_RETENTION_DAYS` (30 days),
then move to private storage for
`BUILD_LOG_ARCHIVE_RETENTION_DAYS` (365 days). Terminal Sentry payloads follow
the equivalent `SENTRY_HOT_RETENTION_DAYS` and compressed-archive
`SENTRY_ARCHIVE_RETENTION_DAYS` windows. Set `SENTRY_ARCHIVE_DISK` to a separate
Laravel filesystem disk when telemetry archives should not share the normal
private media disk. Searchable Sentry metadata remains in PostgreSQL while its
raw payload is cold.

These transitions and final purges are scheduler jobs. Treat an unhealthy
`scheduler` service as a retention-policy failure, and include the private
archive disk in backups for as long as its retention window promises recovery.

## Upgrading

```bash
docker compose pull && docker compose up -d
```

`init` re-runs migrations and `encryption:migrate` before the new containers start
serving. Both are idempotent: `encryption:migrate` creates a key only when none is
active, and leaves already-encrypted credentials alone.

## Backups

Two things: the database and the `storage-app` volume.

```bash
# app_manager here is DB_USERNAME/DB_DATABASE's default — swap both if you changed them.
docker compose exec -T postgres pg_dump -U app_manager app_manager | gzip > app-manager-$(date +%F).sql.gz
# app-manager_storage-app is <project name>_storage-app; compose.yaml's `name:`
# fixes the project name to app-manager, so this only changes if you override
# it yourself with `-p` or COMPOSE_PROJECT_NAME.
docker run --rm -v app-manager_storage-app:/data -v "$PWD:/backup" alpine \
    tar czf /backup/app-manager-storage-$(date +%F).tar.gz -C /data .
```

Back up `APP_KEY` with them. Without it the dump is undecryptable.

## Verifying a change

```bash
APP_MANAGER_IMAGE=app-manager-platform:dev ./smoke-test.sh
```
