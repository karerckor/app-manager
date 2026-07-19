# Platform Auditing — "who did what" + actor in webhook payloads

**Date:** 2026-07-19
**Scope:** Integrate `owen-it/laravel-auditing` into `apps/platform` for a "who did what" audit trail, introduce a shared `ActorContext` for resolving the acting entity, and enrich outgoing webhook payloads with a minimal `actor` block. Covers the audit layer, actor resolution (incl. async/CI attribution), webhook enrichment, UI, retention, and tests.

## Stack

- Laravel 13 / PHP 8.3, Inertia, Fortify + Sanctum
- `owen-it/laravel-auditing` v14 (supports Laravel 11/12/13, PHP ≥8.2) — not yet installed
- Existing Team-based multi-tenancy (`team_id` on all resources)
- Existing domain-event system: `WebhookEvent` (with `actor_user_id`, `schema_version`, `payload`) → `WebhookDelivery` (with `payload_snapshot`)

## Goals

1. **UI** — team/owners can see "who changed what" (team-level audit log + per-app activity feed backed by real data).
2. **Compliance/forensics** — durable, queryable record of changes with actor, channel, IP, and field-level diffs.
3. **Enrich webhooks** — external consumers can see who initiated an action.

## Key context (from codebase)

- `WebhookEvent.actor_user_id` already exists (FK → `users`), with an `actor()` relation. Controllers already pass `$request->user()` as actor to `WebhookEventRecorder::record()`.
- API keys are Sanctum `PersonalAccessToken` records (model `ApiKey`, table `personal_access_tokens`, with `team_id`) **tokenable to a `User`**. So `$request->user()` resolves to a `User` in both web and API requests.
- Sanctum's `$user->currentAccessToken()` returns a `TransientToken` for web-session auth and a `PersonalAccessToken` (our `ApiKey`) for token auth — this is how we distinguish `web` vs `api` without hacks.
- `StartPipelineBuild` / `CreateManualBuild` take a required `User` and store `requested_by_user_id` on `BuildRecord` and `CiCdRun`. Pipeline starts **always** have a human initiator.
- Async continuations (`DispatchCiCdRun` on the `integrations` queue, `PublishWebhookEvent` on `webhooks`) and CI status callbacks run **without** an authenticated user in context.
- `WebhookPayload` currently exposes only aggregate data — **no actor** in payloads today.
- `AppActivityFeed` reconstructs a per-app feed from build records with **no actor** — a real audit trail improves it.
- Webhook `payload_snapshot` is captured at delivery time; adding an actor block is purely additive and versionable via `schema_version`.

## Architecture — two complementary layers, one `ActorContext`

Chosen over (B) deriving everything from auditing — domain events (status transitions, CI/system events) are not simple field diffs and would lose `event_type` semantics — and (C) hand-rolling audit from the webhook stream — loses field-level diffs and misses models/reads not emitting events.

- **Layer 1 — laravel-auditing:** automatic change-data-capture (created/updated/deleted, field-level diffs) on domain + sensitive models → `audits` table.
- **Layer 2 — existing `WebhookEvent`:** stays the semantic domain-event stream; its payload gains an `actor` block.
- Both layers resolve "who / via what" from a single **`ActorContext`**.

## Actor model — originator + source

`ActorContext` (in `app/Support/Audit/`) carries:

| Field | Meaning |
|---|---|
| `originator` | `?User` — who initiated the chain of work |
| `apiKey` | `?ApiKey` — present when the call came via an API key |
| `source` | `web` \| `api` \| `ci_cd` \| `system` — channel of the concrete call |
| `ip`, `userAgent` | request metadata (null for system) |

**Two population paths:**

1. **From request** — resolve from auth. `source = api` when `currentAccessToken()` is a `PersonalAccessToken` (capture `apiKey`), else `web`.
2. **Propagated** — an explicit `ActorContext` is serialized into queued jobs and reconstructed from `requested_by_user_id` for CI callbacks, so async work is attributed to the real initiator with `source = ci_cd`.

**Attribution rules:**

- Direct CRUD (web/api) → `originator` = user, `source` = web|api.
- Build/pipeline start → `originator` = requesting user, `source` = web|api.
- Async continuation of a user action (queue jobs) → propagate the initiator's `ActorContext`.
- CI status callbacks (`running → succeeded/failed`) → `originator` = build's `requested_by` user, `source = ci_cd` (decision: keep the originator link, don't flatten to plain `system`).
- Internal cron (audit pruning, data migrations) → `originator = null`, `source = system`.

## Layer 1 — laravel-auditing

- Install `owen-it/laravel-auditing` v14; publish config + migration.
- **`audits` table additions** (beyond package defaults): `team_id` (tenant isolation + fast scoped queries), `source`, `api_key_id`. Populated via custom resolvers reading `ActorContext`; `user_id`/`user_type` = originator. Indexes: `(team_id, created_at)`, `(auditable_type, auditable_id)`, `(user_id)`.
- **`Auditable` trait** on: `MobileApp`, `AppIdentifier`, `Credential`, `BuildRecord`, `WebhookEndpoint`, `Integration`, tag model(s), `ApiKey`, and Team membership.
- **Redaction:** `Credential` secret/`payload` fields and any token values go in `$auditExclude` — record the fact of change, never the secret value.
- **`team_id`** resolved from the `auditable` (or from `ActorContext` for models without a direct `team_id`).

## Layer 2 — webhook enrichment

- `WebhookPayload` gains an `actor` block builder: `{ type: user|api_key|system, id, display_name, source }` — **no email** (minimal PII). `type` derives from the originator (`api_key` when via key, `system` when no originator); `source` carries web|api|ci_cd|system.
- `WebhookEventRecorder::record()` takes an `ActorContext` (replacing the bare `?User`), writes the actor block into the payload and `actor_user_id` = originator.
- `WebhookEvent.schema_version` → `2`. System events emit `actor: { type: system }`.
- Add nullable `source` column to `webhook_events` (alongside `actor_user_id`) so events can be filtered by channel without parsing JSON.

## UI

- **Team-level Audit Log** — new Inertia page: list of audit entries (actor, action, target, field diff, source, time) with filters by app / user / event type. Access gated by policy (team owners/admins).
- **Per-app feed** — rewrite `AppActivityFeed` to read from `audits`, replacing the build-reconstructed feed and surfacing real actors.

## Retention & performance

- Configurable retention: per-model threshold + a scheduled prune command by age (e.g. 12 months, configurable).
- Indexes as listed above.

## Testing

- Audit rows created with correct originator/source across **web**, **api-key**, and **ci_cd/system** scenarios.
- Secret redaction on `Credential` (diff never contains secret values).
- Tenant isolation — `team_id` scoping of audit queries (ties into the deferred tenant-isolation test backlog).
- Webhook `actor` block present and correct per source; `schema_version` bumped to 2; `source` column populated.

## Out of scope (first pass)

- Auditing high-churn technical models (deliveries, runs, artifacts) — excluded to avoid noise.
- Exposing actor email/role in webhook payloads.
- Audit export / SIEM streaming.
