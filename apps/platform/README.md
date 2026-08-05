# App Manager Platform

The Laravel 13 application that powers App Manager — a self-hosted control plane for mobile application configuration, signing credentials, CI/CD builds, logs, and artifacts.

## Run with Docker

```bash
mkdir app-manager && cd app-manager
curl -O https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/compose.yaml
curl -o .env https://raw.githubusercontent.com/karerckor/app-manager/main/deploy/docker/.env.example
echo "APP_KEY=$(docker run --rm --entrypoint app-manager ghcr.io/karerckor/app-manager-platform:latest generate-key)" >> .env
docker compose up -d
```

Open <http://localhost:8000>. See [`deploy/docker`](../../deploy/docker/README.md) for the startup
presets — installer, env-provided admin, or demo data — and the credential storage modes.

Everything below is for developing the platform itself, not for running an instance.

## Requirements

- PHP 8.4+ and Composer 2
- Node.js 22+ and npm
- PostgreSQL 17
- Redis 8
- PHP extensions: pgsql, pdo_pgsql, redis, zip

## Quick start

Start the infrastructure from the repository root:

```bash
docker compose up -d
```

Set up the platform:

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
```

Run the development server:

```bash
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). The one-time self-hosted installer creates your first owner and team.

## Testing

```bash
composer test
```

Tests use a PostgreSQL database. Create a dedicated test database:

```sql
CREATE DATABASE app_manager_test;
```

Lint and build the frontend:

```bash
npm run verify
```

## API

The REST API is versioned at `/api/v1/{team_slug}`. See the [API v1 reference](docs/api-v1.md) for endpoints, payloads, and examples.

### Client libraries

- [appmctl](../../libs/cli) — Bash CLI client
- [fastlane-plugin-app_manager](../../libs/fastlane-plugin) — Fastlane Ruby plugin

## Integrations

- **GitHub Actions** — connect via GitHub App, discover pipelines, dispatch builds
- **GitLab CI/CD** — OAuth integration, pipeline discovery, self-hosted support
- **Expo EAS Workflows** — GraphQL API integration with automatic project sync

Configuration is handled in the web installer. See [GitLab integration notes](docs/gitlab-integration.md) and [EAS integration](docs/expo-eas-integration.md).

## Architecture

```
Web UI (Inertia/Svelte)  →  Controllers  →  Actions / Support
REST API (v1)           →  Controllers  →  Actions / Support
Queue Workers           →  Jobs         →  Integrations / Webhooks
Scheduler               →  Commands     →  Build log archive, cleanup
```

## Encryption & Key Rotation

Credential payloads and files use **envelope encryption**: a data encryption key (DEK) encrypts the data, and `APP_KEY` (the key encryption key, KEK) encrypts the DEK.

```
APP_KEY (KEK)  →  encrypts  →  DEK (encryption_keys table)
DEK            →  encrypts  →  credential payloads + files
```

This means rotating `APP_KEY` only requires re-encrypting a handful of DEK rows — not every credential in the database.

### Initial setup

After running `php artisan migrate`, create the initial encryption key:

```bash
php artisan encryption:migrate
```

This creates the first active DEK. All new credentials are encrypted with it automatically.

### Check status

```bash
php artisan encryption:status
```

Shows all encryption keys (version, ID, status, created date) and how many credentials are on the current vs legacy format.

### Rotate APP_KEY (KEK)

When you need to change `APP_KEY` (compromise, policy, scheduled rotation):

```bash
# 1. Generate a new key (do NOT set it yet)
php artisan key:generate --show
# Save the output as $NEW_KEY

# 2. Re-encrypt all DEKs with the new KEK
php artisan encryption:rotate-kek \
  --old-key="base64:current-key..." \
  --new-key="$NEW_KEY"

# 3. Update APP_KEY in .env / secrets manager
APP_KEY=$NEW_KEY

# 4. Restart the application
```

All existing credentials remain decryptable. Only the DEK rows in `encryption_keys` are re-encrypted.

### Rotate DEK

To start encrypting new credentials with a fresh data key:

```bash
# Create a new active DEK (the current one is retired, not deleted)
php artisan encryption:rotate-dek

# Optionally re-encrypt existing credentials with the new DEK
php artisan encryption:migrate
```

Retired keys are never deleted — they are still used to decrypt data that was encrypted with them. Run `encryption:migrate` to move existing data to the new active DEK.

### Backward compatibility

Credentials encrypted before envelope encryption (direct `APP_KEY` via Laravel's `Crypt`) are still readable. On read, the legacy format is detected and decrypted transparently. On next write, the data is re-encrypted with the active DEK. The `encryption:migrate` command batch-converts all legacy data at once.

### How it works internally

| Component | Location |
|---|---|
| DEK storage | `app/Models/EncryptionKey.php` |
| Low-level AES-256-CBC | `app/Support/Encryption/EnvelopeEncryptor.php` |
| Key management & caching | `app/Support/Encryption/EncryptionKeyManager.php` |
| Eloquent casts | `app/Support/Encryption/Casts/` |
| File encryption (v2) | `app/Support/Credentials/CredentialFileStorage.php` |

## License

MIT
