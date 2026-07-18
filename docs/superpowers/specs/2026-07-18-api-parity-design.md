# REST API parity with the web UI — design

**Date:** 2026-07-18

**Status:** Design approved; ready for implementation planning

**Baseline:** `main` at `f40e7f7`

**Scope:** bring `/api/v1/{team:slug}` to feature parity with the Inertia web UI by
adding the resource families the API is missing, paginating every collection,
and enforcing a single access-control model (Sanctum scope **and** team role)
shared with the web layer.

## Problem and goals

The web/UI supports more than the JSON API. Today `/api/v1/{team:slug}` covers
apps, identifiers, assets, credentials, builds (manual), and build logs, guarded
only by Sanctum `abilities:*` scopes plus `EnsureTeamMember` (membership and
token↔team binding). The web UI additionally supports GitHub App integrations,
repository/workflow/ref discovery, CI/CD pipeline configuration, automatic
(pipeline) builds and cancellation, outbound webhooks with delivery history, and
team API-key management — all of which enforce the role-based ACL
(`TeamRole` → `TeamPermission`) through Gate/Policy.

The prior integrations design
(`2026-07-18-integrations-github-webhooks-design.md`) explicitly deferred
exposing these features through API tokens "unless a new Sanctum ability and the
same Owner/Admin semantics are designed explicitly." This spec is that explicit
design.

The API must let an authorized caller:

- list linked GitHub App integrations and their status (read-only);
- discover repositories, workflows, and refs for an active integration;
- create, read, update, and delete CI/CD pipeline configurations, and read a
  pipeline's declared `workflow_dispatch` input schema;
- dispatch an automatic (pipeline) build with a ref and non-secret inputs, and
  cancel a build;
- create, read, update, rotate-secret, test, and delete outbound webhook
  endpoints, and read and manually redeliver their deliveries;
- list, create, and revoke team API keys, without privilege escalation;
- page through every collection.

## Non-goals

- Connecting or disconnecting a GitHub App installation through the API. That
  flow is a browser OAuth/redirect and stays in the web UI.
- Re-implementing any domain logic. API controllers are thin adapters over the
  existing Actions, services, and Policies.
- New provider integrations, webhook payload transforms, or any behavior outside
  the referenced integrations design.
- Changing the web UI behavior. Only the API surface and the shared ACL layer
  change.

## Executive decisions

1. **Dual authorization: scope AND role.** Every write and every sensitive read
   requires the token to carry the right Sanctum ability *and* the token owner's
   current team role to grant the mapped `TeamPermission`. A shared policy layer
   backs both web and API.
2. **Declarative enforcement (Approach B).** A new `team.permission:<permission>`
   middleware sits next to `abilities:<scope>` on each route, so scope and role
   are visible together in the route table and cannot be forgotten. Model-level
   checks (ownership, `status->canDispatch()`, `CiCdPipelinePolicy::run`) reuse
   the existing Policies and route-model binding.
3. **Hybrid pagination.** Page-based (`LengthAwarePaginator`) by default; cursor
   pagination for high-volume append-only streams (build logs, webhook
   deliveries).
4. **GitHub App is read + discovery + pipelines + run.** The API lists
   integrations, proxies discovery, manages pipelines, and dispatches/cancels
   builds. It never connects or disconnects an installation.
5. **Thin adapters over existing domain code.** Controllers reuse
   `CreateManualBuild`, `SaveCiCdPipeline`, `StartPipelineBuild`,
   `RequestPipelineBuildCancellation`, `SyncCiCdRunStatus`,
   `CiCdProviderRegistry`, `WorkflowInputValidator`, `WebhookUrlGuard`,
   `WebhookEventRecorder`, and the `IntegrationPolicy`/`CiCdPipelinePolicy`.
6. **No secret leakage.** Plaintext API-key tokens and webhook signing secrets
   appear only in their create/rotate responses. All other responses expose
   hints or metadata only, never installation details, signatures, or payload
   secrets.
7. **API key issuance cannot escalate.** A newly created key's scopes must be a
   subset of both the creating token's scopes (when created by a token) and the
   scopes permitted by the creator's role.

## Access control layer

### New scopes

Added to the `ApiKeyScope` enum and surfaced in `ApiKeyScope::groups()` for the
key-creation UI.

| Scope | Purpose |
|---|---|
| `integrations:read` | List/inspect integrations; discovery (repositories/workflows/refs) |
| `pipelines:read` | View pipeline configurations and their input schema |
| `pipelines:write` | Create/update/delete pipelines |
| `pipelines:run` | Dispatch and cancel automatic builds |
| `webhooks:read` | View webhook endpoints |
| `webhooks:write` | Create/update/delete endpoints, rotate secret, test, redeliver |
| `deliveries:read` | View delivery history and a single delivery |
| `api-keys:read` | List team API keys |
| `api-keys:write` | Create/revoke team API keys |

### Scope → permission map

Both checks must pass. `team.permission` resolves the **token owner's** current
role in the bound team via `team->allows($user, $permission)` and fails closed
for unknown roles. Because the role is read per request, a later role downgrade
immediately narrows the token.

| Resource | Scope | `TeamPermission` |
|---|---|---|
| Integrations / discovery | `integrations:read` | `ManageIntegrations` |
| Pipelines (read) | `pipelines:read` | `ManageIntegrations` |
| Pipelines (write) | `pipelines:write` | `ManageIntegrations` |
| Pipelines (run/cancel) | `pipelines:run` | `RunPipelines` |
| Webhooks | `webhooks:*` | `ManageIntegrations` |
| Deliveries | `deliveries:read` | `ManageIntegrations` |
| API keys (read) | `api-keys:read` | `ManageTeam` |
| API keys (write) | `api-keys:write` | **owner-only** (existing `owner_id` check) |

Existing resources are brought under the same dual model so the ACL is uniform:

| Resource | Scope | `TeamPermission` |
|---|---|---|
| Apps | `apps:*` | `ManageApplications` (read: `View`) |
| Identifiers | `identifiers:*` | `ManageApplications` (read: `View`) |
| Assets | `assets:*` | `ManageApplications` (read: `View`) |
| Credentials | `credentials:*` | `ManageCredentials` (read: `View`) |
| Builds (manual create/update) | `builds:write` | `ManageApplications` |
| Builds / logs (read) | `builds:read` / `logs:read` | `View` |
| Logs (write) | `logs:write` | `ManageApplications` |

Read scopes map to `View` so any member with a read scope can read; write and
sensitive operations require the stronger permission.

### Single source of truth

`ApiKeyScope` gains a `requiredPermission(): ?TeamPermission` method (null for
owner-only `api-keys:write`, resolved separately). The `team.permission`
middleware, the scope-escalation filter, and the key-creation UI all consume
this one mapping, so scope↔role never drifts.

### `team.permission` middleware

- Registered in `bootstrap/app.php` alongside `team.member`.
- Signature `team.permission:<permission>` where `<permission>` is a
  `TeamPermission` value.
- Resolves the acting user (`token->tokenable` for API, session user for web),
  reads the bound `team` route parameter, and aborts 403 unless
  `team->allows($user, $permission)`.
- Fails closed: unknown/absent role or missing team → 403.
- Applied after `abilities:<scope>` so scope failures surface first.

### API key escalation guard

On `POST /api-keys`:

- Requires owner role (`owner_id` check) plus `api-keys:write` scope.
- Requested scopes must be a subset of the intersection of:
  - the scopes permitted by the creator's role (`requiredPermission()` for each
    requested scope must be allowed by the creator's role, with owner-only scopes
    allowed only for the owner); and
  - the scopes of the calling token, when the request is made by a token (a
    web-session request is bounded by role only).
- A violation returns `422` with a field error; no partial key is created.

## Pagination

A shared helper/trait parses `per_page`: default 25, clamped to a maximum of 100
(over-max is clamped, not rejected).

**Page-based** (`->paginate()`), for stable collections: apps, identifiers,
credentials, integrations, pipelines, webhook endpoints, API keys. Response via
API Resource collection: `data[]`, `links`, `meta{current_page, last_page,
per_page, total}`. Query: `?page=<n>&per_page=<n>`.

**Cursor-based** (`->cursorPaginate()`), for high-volume append-only streams:
build logs and webhook deliveries. Response: `data[]`, `meta{per_page,
next_cursor, prev_cursor}` (no `total`). Query: `?cursor=<opaque>&per_page=<n>`.

**Normalization:** endpoints currently returning `->get()` (e.g.
`MobileAppController@index`) move to `->paginate()`. `BuildController@index`
stays page-based with configurable `per_page`. `BuildLogController@show` moves to
cursor pagination.

Discovery endpoints are not wrapped in our pagination: they return the
provider-normalized list in a `{data:[…]}` envelope, since GitHub pagination is
already encapsulated in `GitHubProvider`.

## Endpoints

All under `Route::prefix('v1/{team:slug}')->middleware(['auth:sanctum',
'team.member'])->scopeBindings()->name('api.v1.')`. Each row shows the
`abilities:<scope>` and, where applicable, `team.permission:<permission>`.

### Integrations (read-only)

```
GET  /integrations                integrations:read · ManageIntegrations   (page)
GET  /integrations/{integration}  integrations:read · ManageIntegrations
```

### Discovery (proxy over GitHub, active integration only)

```
GET  /integrations/{integration}/repositories  integrations:read · ManageIntegrations
GET  /integrations/{integration}/workflows      integrations:read · ManageIntegrations   (?repository=)
GET  /integrations/{integration}/refs           integrations:read · ManageIntegrations   (?repository=)
```

Reuse `CiCdProviderRegistry`. Require `integration->status->canDispatch()`
(else 404, matching the web discovery controller). Envelope `{data:[…]}`.

### Pipelines

```
GET    /integrations/{integration}/pipelines                   pipelines:read  · ManageIntegrations  (page)
POST   /integrations/{integration}/pipelines                   pipelines:write · ManageIntegrations
GET    /integrations/{integration}/pipelines/{pipeline}        pipelines:read  · ManageIntegrations
GET    /integrations/{integration}/pipelines/{pipeline}/inputs pipelines:read  · ManageIntegrations   (?ref=)
PATCH  /integrations/{integration}/pipelines/{pipeline}        pipelines:write · ManageIntegrations
DELETE /integrations/{integration}/pipelines/{pipeline}        pipelines:write · ManageIntegrations
```

Reuse `SaveCiCdPipeline`, `WorkflowInputValidator`, `CiCdProviderRegistry`,
`CiCdPipelinePolicy`. `/inputs` returns the declared `workflow_dispatch` input
schema for the pipeline's default ref (or `?ref=` override), from
`GitHubWorkflowDefinition`.

### Builds — automatic dispatch and cancel

(Manual create/update and logs already exist.)

```
POST /integrations/{integration}/pipelines/{pipeline}/runs   pipelines:run · RunPipelines   (body: request_uuid, ref?, inputs?)
POST /apps/{mobileApp}/builds/{buildRecord}/cancel           pipelines:run · RunPipelines
GET  /apps/{mobileApp}/builds/{buildRecord}/logs             logs:read     · View            (cursor)
```

Reuse `StartPipelineBuild` and `RequestPipelineBuildCancellation`. `request_uuid`
enforces dispatch idempotency; a build in `dispatch_status=unknown` refuses a
second dispatch (409). `BuildRecordResource` is extended with `dispatch_status`,
`external_run_url`, and `source` (nullable for manual builds).

### Webhooks and deliveries

```
GET    /webhooks                                            webhooks:read   · ManageIntegrations  (page)
POST   /webhooks                                            webhooks:write  · ManageIntegrations
GET    /webhooks/{webhookEndpoint}                          webhooks:read   · ManageIntegrations
PATCH  /webhooks/{webhookEndpoint}                          webhooks:write  · ManageIntegrations
DELETE /webhooks/{webhookEndpoint}                          webhooks:write  · ManageIntegrations
POST   /webhooks/{webhookEndpoint}/rotate-secret           webhooks:write  · ManageIntegrations
POST   /webhooks/{webhookEndpoint}/test                    webhooks:write  · ManageIntegrations
GET    /webhooks/{webhookEndpoint}/deliveries              deliveries:read · ManageIntegrations  (cursor)
GET    /webhooks/{webhookEndpoint}/deliveries/{delivery}   deliveries:read · ManageIntegrations
POST   /webhooks/{webhookEndpoint}/deliveries/{delivery}/redeliver  webhooks:write · ManageIntegrations
```

Reuse the existing webhook domain services. `WebhookUrlGuard` validates the URL
against SSRF at create/update. The signing secret is returned only from create
and rotate-secret; list/show expose only `secret_hint`. Redeliver creates a new
delivery linked via `redelivery_of_id` and reuses the original event body.

### API keys

```
GET    /api-keys              api-keys:read  · ManageTeam
POST   /api-keys             api-keys:write · owner-only (+ escalation guard)
DELETE /api-keys/{apiKey}    api-keys:write · owner-only
```

The plaintext token is returned only from create.

## JSON resources

New API Resource classes in `App\Http\Resources\Api\V1`, each exposing safe
fields only:

- **`IntegrationResource`** — `id, kind, provider, name, status,
  external_account_name, last_synced_at, created_at, updated_at`. No
  `external_id`/installation details.
- **`CiCdPipelineResource`** — `id, name, enabled, profile_type,
  repository_full_name, workflow_name, workflow_path, default_ref,
  input_defaults, mobile_app_id, app_identifier_id, created_at, updated_at`.
- **`WorkflowInputResource`** (for `/inputs`) — `name, type, required, default,
  options[], description`, from `GitHubWorkflowDefinition`.
- **`WebhookEndpointResource`** — `id, url, secret_hint, subscribed_events,
  enabled, failure_count, last_success_at, last_failure_at, created_at,
  updated_at`. A separate `secret` field appears only in create/rotate responses.
- **`WebhookDeliveryResource`** — `id, event_type, state, attempt_count,
  last_http_status, duration_ms, error_category, redelivery_of_id, delivered_at,
  response_preview (truncated), created_at, updated_at`. No signatures/secrets.
- **`ApiKeyResource`** — `id, name, scopes, created_by{id,name,email},
  last_used_at, created_at`. A `token` field appears only in the create response.
- **`BuildRecordResource`** (extended) — add `dispatch_status`,
  `external_run_url`, `source` (nullable for manual builds).

## Error handling

Uniform JSON error responses:

- **403** — missing scope (`abilities`) or role (`team.permission`), or
  owner-only violation.
- **404** — entity not owned by the bound team (via `assert*`/route-model
  binding), so existence is not disclosed. Also discovery on a non-dispatchable
  integration.
- **409** — invalid build lifecycle transition (existing in `BuildController`);
  ambiguous dispatch (`dispatch_status=unknown` refuses re-dispatch).
- **422** — validation errors (standard `{message, errors}`), including scope
  escalation on key creation, SSRF-rejected webhook URL (`WebhookUrlGuard`), and
  oversized `parameters`/`inputs`.
- **502/504** — GitHub provider error/timeout during discovery or dispatch,
  mapped from `GitHubProvider`, so callers distinguish provider failure from
  their own error.

Idempotency for dispatch and manual create is enforced by the required
`request_uuid`.

## Testing

**Feature tests** are the primary layer (HTTP + ACL + pagination end-to-end),
one file per resource family. For each new endpoint:

- **ACL matrix:** correct scope + role → success; correct scope, insufficient
  role (Contributor/Viewer) → 403; correct role, missing scope → 403; owner-only
  (`api-keys:write`) with Admin + scope → 403.
- **Team isolation:** another team's entity → 404.
- **Pagination:** page-based `per_page` and clamp-to-100 and `meta` shape;
  cursor (logs, deliveries) `next_cursor` advances without overlap.

Feature-specific cases:

- **Scope escalation** on key create: scope wider than role/token → 422; within
  bounds → 201 with the token shown once.
- **Discovery/dispatch:** `GitHubProvider` faked; GitHub errors → 502/504;
  non-dispatchable integration → 404/409.
- **Dispatch idempotency:** repeated `request_uuid` does not duplicate a build;
  `dispatch_status=unknown` → 409 on re-dispatch.
- **Webhooks:** private/loopback URL → 422; create/rotate returns `secret` once,
  list/show expose only `secret_hint`; redeliver creates a new delivery with
  `redelivery_of_id`.
- **Resource leakage:** snapshot checks that responses contain no secrets,
  installation details, or signatures.

**Unit tests** for new pure logic only: `team.permission` middleware
(fail-closed for unknown role), the scope-escalation filter, and the `per_page`
helper.

Existing Actions and services are already covered and are reused, not rewritten.

## Acceptance criteria

- Every new endpoint enforces both the mapped scope and the mapped role; a token
  missing either receives 403, verified by the ACL matrix.
- `api-keys:write` is owner-only and cannot mint a key with scopes exceeding the
  creator's role or token.
- Every collection endpoint paginates; logs and deliveries use cursor
  pagination; over-max `per_page` is clamped.
- The API can list integrations, run discovery, CRUD pipelines, read a
  pipeline's input schema, dispatch and cancel automatic builds, and manage
  webhooks and view/redeliver deliveries — reusing existing domain code.
- No response exposes a plaintext token (except key create), a webhook signing
  secret (except create/rotate), installation details, or delivery signatures.
- GitHub connect/disconnect remain web-only.

## References

- `docs/superpowers/specs/2026-07-18-integrations-github-webhooks-design.md`
- Laravel API resources, pagination, and Sanctum token abilities.
