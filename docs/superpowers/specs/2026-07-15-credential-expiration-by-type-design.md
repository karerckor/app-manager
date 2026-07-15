# Credential expiration field by type

**Date:** 2026-07-15
**Component:** `apps/platform`

## Problem

The credential upload/edit form (`resources/js/Pages/Apps/Show.svelte`) always shows an
optional "Expiration" date field, regardless of credential type. Most credential types have
no meaningful expiration:

- `.p8` keys (App Store Connect API key, APNs key) never expire.
- Firebase / Google service-account and client-config JSON/plist files have no expiration.
- Android keystores are technically valid for 25+ years and are not tracked by expiry.

Only **iOS signing certificates** and **Apple provisioning profiles** actually expire on a
schedule worth tracking (~1 year).

Showing the field for every type is misleading. The backend also accepts `expires_at` for any
type (`nullable|date`), so a stray value can be stored on a credential that conceptually has no
expiration.

## Goal

Show the "Expiration" field only for credential types that expire, and reject `expires_at` on
the backend for types that do not.

Types that expire: `IosCertificate`, `ProvisioningProfile`.
All other types: no expiration field, `expires_at` rejected.

## Design

### 1. Schema — `app/Support/Credentials/CredentialSchema.php`

- Add a `bool $expires = false` parameter to `make()` and include `'expires' => $expires` in
  the returned definition array.
- Pass `expires: true` for `IosCertificate` and `ProvisioningProfile` only.
- Add a helper: `public static function expires(CredentialType $type): bool` returning
  `self::definition($type)['expires']`.

### 2. Backend — `app/Http/Controllers/CredentialController.php`

Both `store()` and `update()`:

- Replace the static `'expires_at' => ['nullable', 'date']` rule with a type-dependent rule:
  - when `$type && CredentialSchema::expires($type)` → `['nullable', 'date']`
  - otherwise → `['prohibited']`
- The rule must be built from the resolved `$type` (already available via
  `CredentialType::tryFrom(...)` before `$request->validate(...)`).

`prohibited` returns a validation error if `expires_at` is present with a non-empty value on a
non-expiring type. (Laravel's `prohibited` fails only when the field is present and not empty,
so an absent field passes.)

`update()` additionally: when the type changes to a non-expiring type, no `expires_at` will be
accepted (rejected by rule) and the existing stored value is not carried over — the update
writes only the validated `expires_at`, so a type change to a non-expiring type must clear it.
Since `expires_at` will be absent from validated data for non-expiring types, explicitly set
`expires_at => null` when the target type does not expire.

### 3. Frontend — `resources/js/Pages/Apps/Show.svelte`

- Wrap the Expiration `<label>` (currently lines ~349-353) in `{#if selectedSchema?.expires}`.
- The type + expiration pair currently sits in a `grid sm:grid-cols-2`. When the expiration
  field is hidden, the single remaining "Credential type" select should still render cleanly.
  Verify layout; if the lone select looks wrong, drop the grid wrapper when
  `!selectedSchema?.expires` (render type full-width).
- In `changeCredentialType`, clear `credentialForm.expires_at = ''` when the newly selected
  schema does not expire, so a stale date is not submitted (and would not trigger `prohibited`).
- The credentials table "Expiration" column already renders `formatDate` → "No expiration" for
  empty values; no change needed.

## Testing

Feature tests (`tests/Feature`, PHPUnit) covering `store` and `update`:

- `expires_at` is accepted and stored for `IosCertificate` (expiring type).
- `expires_at` is rejected (`prohibited` validation error) for a non-expiring type
  (e.g. `FirebaseConfig` or `AppleApiKey`).
- `update` changing a credential from an expiring type to a non-expiring type clears the stored
  `expires_at` (ends up `null`).

## Out of scope

- Changing which types expire beyond the two named.
- Expiration reminders/notifications.
- Auto-detecting expiration from the uploaded certificate file.
