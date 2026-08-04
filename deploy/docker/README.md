# Run App Manager with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example

# APP_KEY encrypts every credential in the instance. Generate it once, keep it.
echo "APP_KEY=$(docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key | tail -1)" >> .env

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
| Throwaway demo instance | `APP_MANAGER_BOOTSTRAP=demo` **and** `APP_ENV=local` → `demo@example.com` / `Demo123!`, API key `demo-api-key` |
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
