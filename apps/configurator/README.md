# App Manager Configurator

A static, dependency-free deployment wizard for generating App Manager environment variables and first-run commands.

## Run locally

From the repository root:

```bash
python3 -m http.server 4173 --directory apps/configurator
```

Then open <http://127.0.0.1:4173>.

The page uses Tailwind CSS and Alpine.js from their CDNs. All configuration and secret generation happens in the browser; the page has no backend and sends no form data.

## Supported routes

- Docker Compose, Laravel Cloud, or a manually managed server.
- First owner through the one-time Web Installer or `php artisan user:create`.
- PostgreSQL fields, platform-injected credentials, or parsed `postgres://` / `postgresql://` connection strings.
- Optional Laravel Cloud Queue output through `QUEUE_CONNECTION=cloud` for environments with managed Cloud Queue enabled.
- Local private storage or an S3-compatible bucket, including import from AWS and `MEDIA_S3_*` environment-variable blocks.
- Credential storage and API access boundaries.
- Review the complete generated `.env` in the browser, copy it, or download it; deployment commands have their own preview.
