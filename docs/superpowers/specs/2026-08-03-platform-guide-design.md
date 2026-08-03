# Platform (Web UI) documentation — design

**Date:** 2026-08-03
**Branch:** `docs/platform-guide` (worktree `.claude/worktrees/docs-platform-guide`, based on `feat/credentials-byov`)

## Goal

Document what the web UI does and how to use the platform through it, with real
screenshots. Installation and project setup are explicitly out of scope — they are a
separate task.

## Decisions

1. **A top-level `/platform/*` section**, a sibling of `/guides/*` rather than nested
   inside it.
2. **Screenshots are captured by a committed Playwright script**, so they can be
   regenerated when the UI changes and the diff shows which pages went stale. This adds
   `playwright` as a dev dependency of `apps/docs`.
3. **The docs seeder is extended** so every documented screen has realistic content.
4. **The OpenAPI spec gets fixed**, not just audited.

### Why not screenshot the existing demo team

`demo-users-team` holds five apps whose names read like real customers (`Beyond Juicery
+ Eatery`, `Lin's Restaurant Group`, `Alanos Eatery`, `Aero Crafters`). Screenshots are
far harder to audit for leaks than text, and an image cannot be partially redacted after
it ships. All capture happens in `acme-mobile`, the team `scripts/docs/seed-capture-data.php`
creates for itself, logged in as `docs-capture@example.com`.

## Screen inventory

Team-scoped, under `/accounts/{team}`:

| Route | Screen | Documented on |
| --- | --- | --- |
| `/apps` | Applications catalog | `/platform/applications` |
| `/apps/{slug}` | Application overview | `/platform/applications` |
| `/apps/{slug}/identifiers`, `/identifiers/{id}` | Identifiers | `/platform/applications` |
| `/apps/{slug}/media` | Files, icon, splash screen | `/platform/files-and-assets` |
| `/apps/{slug}/builds`, `/builds/{id}` | Build history and one build | `/platform/builds` |
| `/apps/{slug}/expo` | Expo link for one app | `/platform/integrations/eas` |
| `/credentials` | Credentials Vault | `/platform/credentials-vault` |
| `/api-keys` | API keys | `/platform/api-keys` |
| `/audit` | Audit log | `/platform/audit-log` |
| `/team` | Team access | `/platform/team-access` |
| `/integrations` | CI/CD integrations | `/platform/integrations` |
| `/integrations/cicd/{id}` | One CI/CD integration and its pipelines | `/platform/integrations` |
| `/integrations/eas` | Expo accounts and projects | `/platform/integrations/eas` |
| `/integrations/webhooks` | Webhook endpoints | `/platform/integrations/webhooks` |
| `/integrations/webhooks/{endpoint}/deliveries/{id}` | One delivery | `/platform/integrations/webhooks` |
| `/integrations/notifications` | Chat notification destinations | `/platform/integrations/notifications` |
| `/tools/push-notifications` | Push notification tool | `/platform/push-notifications` |

`/tools/push-notifications` was not in the request. It is a real feature with a UI, so it
gets a short page rather than being silently omitted.

## Page structure

```
/platform                                overview, navigation map, core concepts
/platform/applications                   apps, overview, identifiers
/platform/files-and-assets               media library, icon, splash screen
/platform/builds                         history, one build, logs, artifacts, manual builds
/platform/credentials-vault              credential types, vault storage, verification
/platform/api-keys                       issuing keys, scopes, rotation
/platform/audit-log                      what is recorded, filtering
/platform/integrations                   what an integration is, CI/CD and pipelines
/platform/integrations/eas               Expo accounts, project links, identifier sync
/platform/integrations/webhooks          endpoints, signing, deliveries
/platform/integrations/notifications     chat destinations, templates, events
/platform/team-access                    roles, permissions, invitations
/platform/push-notifications            the push testing tool
```

Each page states what the screen is for, walks the primary task, and shows the captured
screenshot. Where the CLI can do the same job, the page links to the matching CLI guide
rather than repeating it.

## Screenshot capture

`scripts/docs/capture-ui-screenshots.mjs`:

- takes the instance URL, email, and password from the environment; no credentials in the
  script
- logs in once and reuses the session
- 1440×900, light theme, `scale: css` so images stay identical across machines
- writes `apps/docs/public/screenshots/<slug>.png`
- fails on a screen that does not render, so a renamed route breaks the capture run
  instead of leaving a stale image in place

Pages reference screenshots through a `Screenshot` component that resolves the file at
build time, so a missing or renamed image fails the docs build rather than rendering a
broken image.

## Seeder extension

`scripts/docs/seed-capture-data.php` grows to cover screens that are currently empty:

- three more applications so the catalog has rows worth showing
- builds across `completed`, `running`, `failed`, `pending`, with tags, logs, artifacts
- audit log entries, so `/audit` is not empty
- two more team members and one pending invitation
- three API keys with visibly different scope sets
- notification destinations for Slack and Discord
- webhook deliveries including one failure, so the delivery view shows a real error

Still additive and idempotent, and still confined to its own team.

## OpenAPI fixes

Comparing `routes/api.php` (93 method+path pairs, 57 distinct paths) against
`apps/docs/public/openapi.yaml` (51 paths):

Genuinely missing:

- `GET /apps/{app_slug}/builds/{build}/logs/archive`
- `POST /apps/{app_slug}/builds/{build}/sync-eas`
- `POST /apps/{app_slug}/credentials/{credential}/reveal`
- `/apps/{app_slug}/credentials/{credential}/verification`
- `PATCH /apps/{app_slug}/expo/automatic-sync`
- `POST /apps/{app_slug}/expo/identifiers/sync`

Cosmetic but worth fixing: the spec uses `{build}`, `{identifier}`, `{mobileApp_slug}`,
`{project_id}`, and `{expo_api_path}` where the routes use `{buildRecord}`,
`{appIdentifier}`, `{app_slug}`, `{expoProject}`, and `{path}`. Path templates should read
the same in both places so this comparison stays cheap to repeat.

Response bodies get checked against the API Resources, the same check that turned up four
wrong CLI output formats during the CLI guide work.

## Verification

- `pnpm build` succeeds and every internal link resolves
- every screenshot referenced by a page exists on disk
- no captured image contains another team's data
- the CLI suites still pass, since the seeder is shared with the CLI capture harness

## Out of scope

- Installation and project setup pages.
- Authentication screens (login, 2FA, password reset) — part of the setup story.
- The installer UI.
