# Contributing to App Manager Platform

Thanks for your interest in contributing. This document outlines the conventions and workflow for the Platform application.

## Setup

See the [root README](../../README.md#local-quick-start) for local development setup with Docker, PostgreSQL, and Redis.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
```

## Development workflow

### Running the application

```bash
composer run dev
```

This starts the server, Vite, queue worker, scheduler, and log viewer concurrently.

### Running tests

```bash
composer test
```

Tests run against a separate `app_manager_test` PostgreSQL database. Create it first:

```sql
CREATE DATABASE app_manager_test;
```

The test suite uses `RefreshDatabase` and runs migrations automatically.

### Linting

```bash
# PHP
vendor/bin/pint --test

# PHP auto-fix
vendor/bin/pint

# JS design lint
npm run design:lint

# JS design tests
npm run test:design

# Full frontend verification
npm run verify
```

## Code conventions

- Follow PSR-12 coding style enforced by Laravel Pint
- Use strict types at the top of all PHP files
- Database migrations use Laravel schema builder; avoid raw SQL unless explicitly driver-gated
- UUIDs are generated in PHP via `HasUuids` trait, not at the database level
- Credential and token payloads are application-encrypted before storage
- API design follows the [API v1 reference](docs/api-v1.md)
- New features should include both unit and feature tests
- Feature tests use `RefreshDatabase` and a real PostgreSQL database

## Pull requests

1. Fork the repository and create a branch from `main`
2. Write or update tests for your changes
3. Run `composer test` and `npm run verify` locally
4. Ensure PHP lint passes: `vendor/bin/pint --test`
5. Open a pull request against `main`
6. CI will run linting and tests automatically

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/) format:

```
feat(platform): add credential rotation scheduler
fix(api): handle null build parameters in response
```

Prefix with platform context where helpful: `feat(platform)`, `fix(api)`, `docs`, `test`, `chore`.

## Architecture

- **app/Actions** — self-contained operations with validation, authorization, and side effects
- **app/Enums** — typed PHP enums backed by string values
- **app/Integrations** — GitHub, GitLab, and Expo provider abstractions
- **app/Models/Concerns** — shared traits (HasTags, BelongsToTeam)
- **app/Support** — credential schemas, file normalization, scope resolution
- **config** — all application configuration, environment-driven
- **routes** — web (Inertia), API v1, and console commands

## License

This project is open-sourced under the [MIT license](LICENSE).
