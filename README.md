# App Manager monorepo

The repository contains the Laravel/Inertia platform and a Fastlane plugin skeleton.

## Packages

- `apps/platform` — Laravel 13, Fortify authentication and 2FA, Sanctum API keys, teams, Inertia.js and Svelte 5.
- `libs/fastlane-plugin` — Ruby gem skeleton for a Fastlane plugin.
- `docker-compose.yml` — PostgreSQL and Redis for local development.

## Quick start

```bash
docker compose up -d
cp apps/platform/.env.example apps/platform/.env
cd apps/platform
composer install
npm install
php artisan key:generate
php artisan migrate
composer run dev
```

The platform is available at `http://localhost:8000`. Vite, the queue worker and logs run with the Composer development command.

## Fastlane plugin

```bash
cd libs/fastlane-plugin
bundle install
bundle exec rspec
```

Configure the API with `app_manager_configure`, then combine the typed asset and credential download actions described in [`libs/fastlane-plugin/README.md`](libs/fastlane-plugin/README.md).
