# Credentials: BYOV, storage-mode policy, dashboard-only profile

**Date:** 2026-08-02

Three changes to how the platform holds signing secrets, delivered together because they share a validation path and a UI surface:

1. **BYOV (Bring Your Own Vault)** — a credential may hold a *reference* to a secret in the team's own vault instead of the secret itself.
2. **Storage-mode policy** — which modes are permitted, set as a ceiling by the instance and narrowed by each team.
3. **`dashboard-only` deployment profile** — a deployment where secret material never traverses the API.

## Why

Today every credential must carry its secret: a file in MediaLibrary plus an encrypted `payload`. Teams that already run AWS Secrets Manager, HashiCorp Vault or `fastlane match` cannot use the platform without duplicating their secrets into a second store, which is exactly the thing their security policy forbids.

BYOV inverts the relationship. The platform keeps what it is good at — knowing *which* credential belongs to which app, which build profile needs it, and when it expires — and stops holding the bytes. The pipeline receives an address and resolves it itself, by whatever means the team already uses.

## Terminology

- **Storage mode** — a property of one credential: `local` (platform holds the secret) or `external` (platform holds a reference).
- **Vault reference** — the address plus its storage metadata, for an `external` credential.
- **Verification** — a pipeline's report that it successfully resolved a vault reference.
- **API profile** — a deployment-level setting controlling which credential endpoints exist.

---

## Data model

### Enums

```php
App\Enums\CredentialStorageMode:      Local = 'local' | External = 'external'
App\Enums\VaultProvider:              aws_secrets_manager | hashicorp_vault | azure_key_vault
                                    | gcp_secret_manager | fastlane_match | custom
App\Enums\CredentialVerificationStatus: Unverified | Verified | Failed
App\Enums\CredentialApiProfile:       Full = 'full' | DashboardOnly = 'dashboard_only'
```

The platform never dispatches on `VaultProvider` to do work — it does not talk to any vault. The enum exists to render the right label and the right placeholder, and to keep the value set closed so the vault page can group by it. `custom` is the escape hatch.

### `credentials` — one new column

| Column | Type | Notes |
|---|---|---|
| `storage_mode` | string, NOT NULL, default `local`, indexed | `CredentialStorageMode` |

The migration backfills every existing row to `local`; nothing else about existing credentials changes.

**`storage_mode` is immutable after creation.** The form omits it when editing, `update` ignores it, and an API `PATCH` carrying a different value returns 422. Converting `local → external` would mean deleting the only copy of a keystore on the strength of a reference nobody has yet proven resolves. Changing mode means creating a new credential, moving the attachments, and deleting the old one — three deliberate steps, each individually reversible.

### `credential_vault_references` — new table, 1:1, external only

| Column | Type | Notes |
|---|---|---|
| `id` | uuid, pk | |
| `credential_id` | uuid, unique, FK → `credentials`, cascade delete | |
| `team_id` | uuid, FK → `teams`, cascade delete | denormalised for the uniqueness index |
| `provider` | string | `VaultProvider` |
| `resource` | text | ARN, vault path, URI — **not encrypted** |
| `metadata` | jsonb, nullable | **storage metadata only** |
| `metadata_version` | unsigned integer, default 1 | incremented on every rewrite of `metadata` |
| `metadata_source` | string | `user` \| `pipeline` \| `import` |
| `verification_status` | string, default `unverified` | `CredentialVerificationStatus` |
| `verified_at` | timestamp, nullable | |
| `verification_error` | text, nullable | reason for `failed` |
| `created_at` / `updated_at` | | |

Indexes: `unique(credential_id)`, `index(team_id, provider, resource)`.

No row exists for a `local` credential. The presence of the row *is* the mode, redundantly with `credentials.storage_mode`; the column exists so the vault page can filter and count without a join.

`App\Models\CredentialVaultReference` implements `Auditable`, like `Credential`. Verification history therefore lands in the existing audit log without a dedicated events table.

**`resource` is stored in plaintext.** The entire premise of BYOV is that the instance holds nothing secret. An ARN is an address, not a secret. Plaintext keeps it searchable, displayable in the vault, and deliverable to the pipeline without a DEK decryption round-trip. A team that considers its vault paths sensitive should not be exposing an app-manager instance to that threat model in the first place.

### Two kinds of metadata, kept apart

- **Storage metadata** — `credential_vault_references.metadata`. Region, `version_id`, mount point, namespace. Describes *where the secret lives*.
- **Credential metadata** — `credentials.payload`, unchanged. `key_id`, `team_id`, `issuer_id`, `key_alias`. Describes *the secret itself*.

They are never merged into one blob. This is what makes the following validation rule fall out cleanly rather than needing a hand-maintained list:

> **In `external` mode, the file is not collected and neither are fields whose `input` is `password`. Everything else is collected as usual.**

`CredentialSchema` already classifies fields by input type, so the rule needs no new data. It lands correctly on every existing type:

| Type | Collected in `external` | Lives in the vault |
|---|---|---|
| `ios_certificate` | — | `.p12`, `password` |
| `provisioning_profile` | — | `.mobileprovision` |
| `apns_key` | `key_id`, `team_id` | `.p8` |
| `apple_api_key` | `key_id`, `issuer_id` | `.p8` |
| `android_keystore` | `key_alias` | `.jks`, `store_password`, `key_password` |
| `firebase_config`, `google_play_service_account`, `google_services_json`, `google_service_info_plist` | — | the JSON / plist |

`key_id`, `team_id`, `issuer_id` and `key_alias` are not secrets, and the pipeline needs them to build. They stay where they already are.

### `metadata_version` and `metadata_source`

`metadata_version` starts at 1 and increments on every rewrite. It gives the UI something honest to show ("storage metadata updated 3 times") and gives a future reader a cheap way to detect that two concurrent pipelines are fighting over the same reference.

`metadata_source` records who wrote the current version: `user` (entered in the form or via `POST /credentials`), `pipeline` (a verification report), `import` (reserved for a future bulk importer — no code path writes it in this change).

### Teams

| Column | Type | Notes |
|---|---|---|
| `allowed_credential_storage_modes` | jsonb, nullable | `null` = inherit the instance ceiling |

Sits alongside the existing `check_credential_uniqueness_by_default`.

---

## Storage-mode policy

```php
// config/credentials.php
'storage_modes' => env('CREDENTIAL_STORAGE_MODES', 'local,external'),
```

`App\Support\Credentials\StorageModePolicy` is the single resolver. Every consumer — the two forms, the create/update validation, the API, the team settings page — goes through it and nowhere else.

```php
StorageModePolicy::instanceModes(): array          // parsed + validated from config
StorageModePolicy::allowedFor(Team $team): array   // intersection, with the fallback below
StorageModePolicy::permits(Team $team, CredentialStorageMode $mode): bool
```

Resolution: `instance ∩ team`. **If the intersection is empty, fall back to the instance ceiling rather than to an empty set.** An empty set would block credential creation entirely, so a team that saved `['external']` before the operator narrowed the instance to `['local']` would be locked out of its own vault with no in-app way to recover. Falling back keeps the instance's policy authoritative and the team functional.

An unparseable or empty `CREDENTIAL_STORAGE_MODES` falls back to `['local']` — the behaviour of every deployment before this change.

When exactly one mode is permitted, the mode selector is not rendered and the value is applied silently.

### Uniqueness

`fingerprint` is derived from file bytes, so it is meaningless for `external`. The analogue on the other key: a duplicate is a second credential of the same type in the same team with the same `(provider, resource)`. This reuses the existing `teams.check_credential_uniqueness_by_default` flag — the same switch, applied to whichever identity the mode makes available. `App\Support\Credentials\CredentialUniqueness` gains an `external` branch; no new setting.

---

## API

### Creating an external credential

```
POST /api/v1/apps/{mobileApp:slug}/credentials
{
  "type": "apns_key",
  "name": "APNs production",
  "storage_mode": "external",
  "vault": {
    "provider": "aws_secrets_manager",
    "resource": "arn:aws:secretsmanager:eu-central-1:123456789012:secret:apns-prod-AbCdEf",
    "metadata": { "region": "eu-central-1" }
  },
  "payload": { "key_id": "ABCD123456", "team_id": "TEAM123456" },
  "expires_at": "2027-03-01T00:00:00Z",
  "tags": ["production"]
}
```

Validation:

| Field | Rule |
|---|---|
| `storage_mode` | `required` when more than one mode is permitted, otherwise `nullable`; must be in `StorageModePolicy::allowedFor($team)` |
| `vault` | `required_if:storage_mode,external`, `prohibited` when `local` |
| `vault.provider` | `required`, `Rule::enum(VaultProvider::class)` |
| `vault.resource` | `required`, `string`, `max:2000` |
| `vault.metadata` | `nullable`, `array`, values scalar only (no nesting) |
| `file` | `prohibited` when `external` |
| `payload.*` where `input === 'password'` | `prohibited` when `external` |
| `expires_at` | `nullable`, `date` |

A past `expires_at` is accepted rather than rejected, matching the verification endpoint below, which must be able to report a secret that has genuinely expired. One rule for both writers; the vault renders the row as expired immediately, which is honest feedback rather than a validation error.

`file` and password fields are **`prohibited`, not `nullable`**. A password silently accepted and encrypted in a BYOV credential is precisely the leak the mode exists to prevent; the request must fail loudly.

`vault.metadata` values are restricted to scalars. It is a description of a storage location, not a place to park an arbitrary tree.

A newly created external credential starts at `verification_status = unverified`, `metadata_source = user`, `metadata_version = 1`.

The same rules apply to the web `POST` route; both go through one form request.

### Verification report

```
POST /api/v1/apps/{mobileApp:slug}/credentials/{credential}/verification
{
  "status": "verified",
  "expires_at": "2027-03-01T00:00:00Z",
  "metadata": { "version_id": "a1b2c3" }
}
```

| Field | Rule |
|---|---|
| `status` | `required`, `in:verified,failed` |
| `error` | `required_if:status,failed`, `string`, `max:1000`; `prohibited` when `verified` |
| `expires_at` | `nullable`, `date`; `prohibited` when `failed` |
| `metadata` | `nullable`, `array`, scalars only |

- New scope `ApiKeyScope::CredentialsVerify = 'credentials:verify'`, added to the CI/pipeline preset. `credentials:write` is not reused — it also permits deletion, which is far too much for a build token.
- `throttle:60,1`, matching the density of `reveal`.
- Returns 422 for a `local` credential: there is no reference to verify.
- `verified` sets `verification_status`, `verified_at = now()`, clears `verification_error`; `expires_at`, when present, overwrites the credential's.
- `failed` sets the status and `verification_error`, and leaves `verified_at` at its previous value — "last known good" is more useful than a cleared field.
- `metadata`, when present, **replaces the block wholesale**, increments `metadata_version`, and sets `metadata_source = pipeline`. Deep-merging partial reports from two concurrent builds produces a blend that describes neither.

### Webhook: `credential.verification_failed`

One new `WebhookEventType`. It fires **on the transition into `failed`** (from `unverified` or `verified`), not on every report — a build retrying in a loop would otherwise emit a hundred identical webhooks. The reverse transition emits nothing: recovery is visible in the vault, and a symmetric `credential.verified` has no consumer.

### Read shape — additive

`index` and `show` gain two keys; no existing key changes meaning, so no current consumer breaks. The CLI and the fastlane plugin are extended below to *use* the new keys, but an un-upgraded copy of either keeps working.

```json
{
  "storage_mode": "external",
  "file": null,
  "vault": {
    "provider": "aws_secrets_manager",
    "resource": "arn:aws:secretsmanager:eu-central-1:…:secret:apns-prod-AbCdEf",
    "metadata": { "region": "eu-central-1" },
    "metadata_version": 3,
    "metadata_source": "pipeline",
    "verification": {
      "status": "verified",
      "verified_at": "2026-08-01T09:14:00Z",
      "error": null
    }
  }
}
```

For `local`: `storage_mode: "local"`, `vault: null`, `file` exactly as today.

### What switches off by itself

- **`download`** → 404. There is no media; this is the existing behaviour for a file-less credential. Locked in by a test.
- **Expo push** → `expo.available` already requires `$media !== null`, so it is `false` for external with no code change.
- **`reveal`** → this change exposes an existing defect. `CredentialPresenter` sets `reveal_url` whenever `payload !== []`. An external APNs key has a non-empty payload (`key_id`, `team_id`), so "reveal" would ceremonially disclose two non-secret values already present in the response.

  **Fix:** `reveal_url` is set only when the payload contains at least one field whose schema `input` is `password`. For every `local` credential the result is identical to today.

### What is unaffected by mode

Attaching to app identifiers and build profiles, reuse of one credential across identifiers, tags, the audit log, and deletion all behave identically for both modes. Mode governs where the secret lives, nothing about how a credential relates to the rest of the platform.

---

## The `dashboard-only` API profile

```php
// config/credentials.php
'api_profile' => env('CREDENTIALS_API_PROFILE', 'full'),   // full | dashboard_only
```

Read once, when routes load. `CredentialApiProfile` exposes a single predicate, `exposesSecretMaterial(): bool`. This follows the existing `APP_DISTRIBUTION` precedent in `config/installer.php`.

### Routes that are not registered

Under `dashboard_only` these are **absent**, producing 404 rather than 403:

- `POST /apps/{app}/credentials/{credential}/reveal`
- `GET /apps/{app}/credentials/{credential}/file`
- `POST /apps/{app}/credentials/{credential}/file`
- `DELETE /apps/{app}/credentials/{credential}/file`
- `POST /apps/{app}/expo/identifiers/{appIdentifier}/credentials/{credential}`
- `POST /apps/{app}/expo/identifiers/{appIdentifier}/provision`

The Expo routes are included because they push secret files out to Expo — the same class of egress the profile exists to stop.

`ApiKeyScope::CredentialsReveal` is removed from the assignable list and from the key-creation UI. **The enum case itself stays** — issued keys hold that string in the database and must not become invalid. They simply have nothing to reach.

### Web routes are untouched

The dashboard remains fully functional, including reveal, upload and download. That is what the profile's name means.

### Credential webhooks and notifications are off

All eight credential events — `created`, `updated`, `deleted`, the four `expiring_*` / `expired`, and the new `verification_failed` — are removed from the registerable list and are not dispatched. A webhook payload carrying credential names, types and expiry dates to an arbitrary external URL is the same egress the profile prevents at the route layer.

`CredentialExpiryMonitor` keeps running: the vault still shows what is rotting, the instance just does not announce it outward.

### How the two features compose

`dashboard_only` + `external` is the coherent target combination. The pipeline reads `vault.resource` from `GET /apps/{app}/credentials` — metadata, not secret material — pulls the secret from its own vault, and reports back to `/verification`. The instance neither serves nor accepts a single secret byte over the API.

`dashboard_only` + `local` is valid but hostile: the pipeline cannot obtain the file at all. It only makes sense for teams distributing secrets to CI by some other channel. **The documentation must say this plainly**, or an operator will enable the profile and break every build.

---

## UI

### Vault page (`Credentials/Index.svelte`)

An external row differs on its second line only: `{type label} · {provider label} · {resource}`, with `resource` in mono and truncated. The amber "no secure file uploaded" text does not appear — an external credential is not supposed to have one.

**Consequently, the `missing_file` filter and stat must exclude `storage_mode = 'external'`.** Without this every BYOV credential lands in the "no file" count on day one and the feature looks broken.

Validity is unchanged: the runway meter reads `expires_at`, which both modes have. Beside it, a verification chip: `failed` in rose, `unverified` in slate, `verified` renders nothing — following the rule established in the 2026-07-29 vault spec that colour marks what needs action.

- **Toolbar** gains a `Storage` select (All / Local / External). Lookup belongs in the toolbar.
- **Chip rail** gains one chip, `Verification failed`, in rose. Triage belongs in the rail.

No `unverified` filter chip. The state is legible in the row, and a ninth chip does not earn its place in the rail. Deferred, not rejected.

### Forms

Both `Credentials/VaultCredentialFormModal.svelte` and `Apps/Modals/CredentialFormModal.svelte`:

- A segmented Local / External control at the top, rendered only when more than one mode is permitted.
- **Local branch** — unchanged.
- **External branch** — provider select; `resource` input in mono with a provider-specific placeholder (`arn:aws:secretsmanager:…`, `secret/data/…`, `https://…vault.azure.net/secrets/…`); optional `expires_at` date; a key-value editor for storage metadata; and the type's non-password fields.
- When editing, the control is disabled with a caption explaining that mode is fixed at creation.

**Both modals are converted to runes.** They currently use `export let` — legacy mode, in which `useForm` does not re-render `errors` or `processing`. This whole branch rests on server-side validation ("resource is required", "that mode is not permitted", "password fields are prohibited in external mode"); in a legacy-mode form the user would press Save and see nothing at all. The conversion is in scope.

### Team settings (`Team/Index.svelte`)

Checkboxes for permitted storage modes, beside `check_credential_uniqueness_by_default`. Only modes within the instance ceiling are offered; if the ceiling holds one mode, the block is not rendered.

---

## CLI and fastlane

**`libs/cli/lib/commands/credentials.sh`**

- `list` gains a storage column.
- `show` renders the vault block.
- New: `appmctl credentials verify <id> --status ok|failed [--error "…"] [--expires-at …]`

**fastlane plugin**

- `app_manager_list_credentials_action` prints storage mode and resource in its table, and keeps stripping `payload` from lane context as it does today.
- New action `app_manager_report_credential_verification`. Without it nobody populates verification, and the field is decoration.

**Docs** (`apps/docs`): the BYOV concept page, the `/verification` endpoint, the two config variables, and the warning about `dashboard_only` + `local`.

---

## Testing

Feature tests on `RefreshDatabase`, following the existing `tests/Feature/Credential*Test.php` conventions.

**Policy**
- `instance ∩ team` resolves correctly for each combination
- an empty intersection falls back to the instance ceiling
- an unparseable `CREDENTIAL_STORAGE_MODES` falls back to `['local']`
- creating with a non-permitted mode returns 422

**External credentials**
- `file` and password fields are rejected, not ignored
- non-password payload fields are stored
- `storage_mode` cannot be changed by `update` or `PATCH`
- a second credential with the same `(team, type, provider, resource)` is a duplicate when the team's uniqueness flag is on, and permitted when it is off

**Verification**
- `verified` and `failed` write the expected fields; `failed` preserves `verified_at`
- `expires_at` is rejected with `failed`; `error` is required with `failed`
- a `local` credential returns 422
- `metadata` replaces wholesale, increments `metadata_version`, sets `metadata_source = pipeline`
- `credentials:verify` is required; `credentials:read` alone is rejected

**Webhooks**
- `verification_failed` fires on transition into `failed`
- it does not fire on a repeated `failed` report
- it does not fire on recovery

**`dashboard_only`**
- all six routes return 404
- their web counterparts still work
- no credential event is dispatched, including from `CredentialExpiryMonitor`
- `credentials:reveal` is not offered when creating an API key

**Vault page**
- `missing_file` excludes external
- the `storage` filter and the `Verification failed` chip return the right sets

**Regression**
- `download` returns 404 for external
- `reveal_url` is `null` for external, and unchanged for every local credential
- `expo.available` is `false` for external
- existing vault, credential and Expo tests pass unchanged

---

## Accepted risks

1. **`verified_at` means "a pipeline resolved this once", not "this is valid now."** The secret may have been revoked a minute later. Mitigated only by displaying the age of the verification and letting it fade — there is no vault polling in this change, and by design there never will be.
2. **A hand-entered `expires_at` on an external credential is unverified by construction** until a pipeline reports. Accepted: the alternative is a vault that is blind until the first build.
3. **`dashboard_only` cannot be verified on a running instance without a restart**, because routes are resolved at load. That is the requirement, not a defect — but the docs must state that switching profiles is a deploy, not a toggle.

## Rejected alternatives

**A polymorphic `credential_storages` table** with a row for `local` as well as `vault`. Cleaner as an abstraction, but it rewrites the entire working local path and migrates 251+ existing rows to buy symmetry nobody consumes. The two modes genuinely differ; a nullable 1:1 side table says so honestly.

**One `metadata` JSON per credential** holding both storage and credential facts. Rejected on the same grounds the split was requested: the two have different owners (`user` vs `pipeline`), different lifetimes, and different sensitivity. Merged, neither can be validated.

**Per-`CredentialType` mode policy** (e.g. keystores must be external, `google-services.json` may be local). Real use case, but it adds a third policy level, a matrix in the settings UI, and a resolution order to explain. Deferred until someone asks for it.

**Platform-side vault resolution** — app-manager holding AWS credentials and fetching secrets itself. Directly contrary to the point: it would make the instance a higher-value target than the vault it reads.

## Follow-ups (not now)

- Bulk import of vault references (the `metadata_source = 'import'` value is reserved for it).
- An `unverified` filter chip, if triage demand appears.
- Re-verification staleness policy — flagging a credential whose last successful verification is older than N days.
