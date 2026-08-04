# Run App Manager with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example

# APP_KEY encrypts every credential in the instance. Generate it once, keep it.
echo "APP_KEY=$(docker run --rm --entrypoint app-manager ghcr.io/karerckor/app-manager-platform:latest generate-key)" >> .env

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
| Throwaway demo instance | `APP_MANAGER_BOOTSTRAP=demo` **and** `APP_ENV=local`, with `APP_KEY` **commented out** (not just blank — see below) → `demo@example.com` / `Demo123!`, API key `demo-api-key` |
| Bring your own vault only | `CREDENTIAL_STORAGE_MODES=external` |
| Platform-held secrets only | `CREDENTIAL_STORAGE_MODES=local` |
| Both (default) | `CREDENTIAL_STORAGE_MODES=local,external` |
| Secrets never traverse the API | `CREDENTIALS_API_PROFILE=dashboard_only` |

Changing any of these is `docker compose up -d` away: the containers are
recreated and the new configuration is picked up at boot.

For demo mode, `APP_KEY` must be **absent** from `.env`, not merely blank —
comment out the whole line (`# APP_KEY=`). An empty value is still a defined
one as far as the container is concerned, and a defined-but-empty `APP_KEY`
permanently shadows the key the container would otherwise generate for you.

`queue` and `scheduler` have no healthcheck (there's no web server in either
role for one to check), so `docker compose ps` always shows them without a
health column. Confirm they're actually working with
`docker compose logs queue scheduler`.

## Upgrading

```bash
docker compose pull && docker compose up -d
```

`init` re-runs migrations before the new containers start serving.

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
