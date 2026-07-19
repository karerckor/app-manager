# Delete App Identifiers and Credentials from the UI

**Date:** 2026-07-19
**Status:** Approved for planning

## Problem

The web UI (Inertia/Svelte app show page) has no way to delete app identifiers or
credentials. The REST API (`api.php`) already exposes `destroy` endpoints for both,
with full webhook recording, but the web controllers and routes do not, and the UI
renders no delete buttons.

## Goal

Add delete buttons for app identifiers and credentials on the app show page, backed
by web `destroy` routes and controller actions that mirror the existing API behavior.

## Decisions

- **Credential deletion semantics:** full delete — mirror the API `destroy`
  (`$credential->delete()`). If a credential is shared across apps/identifiers, it is
  removed everywhere. Keeps parity with the API.
- **Confirmation UX:** inline confirmation, matching the existing app-delete pattern
  (Delete button expands to "Delete? / Cancel / Confirm"). No native browser dialogs.
- **Identifier with attached credentials/build targets:** allow deletion (API parity),
  but warn in the inline confirmation using `credentials_count`.

## Backend

### Routes (`apps/platform/routes/web.php`)

Add inside the existing authenticated `accounts.` route group:

- `DELETE /apps/{mobileApp:slug}/identifiers/{appIdentifier}`
  → `AppIdentifierController@destroy`, name `apps.identifiers.destroy`
- `DELETE /credentials/{credential}`
  → `CredentialController@destroy`, name `credentials.destroy`

(The group's `accounts.` name prefix yields `accounts.apps.identifiers.destroy` and
`accounts.credentials.destroy`.)

### `AppIdentifierController@destroy`

Mirror `Api\V1\AppIdentifierController@destroy`:

1. `abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403)`
2. `DB::transaction`: capture `WebhookPayload::appIdentifier($appIdentifier)`,
   `$appIdentifier->delete()`, record `WebhookEventType::AppIdentifierDeleted`.
3. `return back()->with('status', 'App identifier removed.')`

### `CredentialController@destroy`

New method on the web `CredentialController`:

1. `$this->authorizeTeam($request, $team)` and `abort_unless($credential->team_id === $team->id, 404)`
2. `DB::transaction`: capture `WebhookPayload::credential($credential)`,
   `$credential->delete()`, record `WebhookEventType::CredentialDeleted`.
3. `return back()->with('status', 'Credential removed.')`

## Serialization (`apps/platform/app/Http/Controllers/MobileAppController.php`)

- Identifier array: add `delete_url` →
  `route('accounts.apps.identifiers.destroy', ['team', 'mobileApp', 'appIdentifier'])`.
- `credentialData()`: add `delete_url` →
  `route('accounts.credentials.destroy', ['team', 'credential'])`.

## Frontend (`apps/platform/resources/js/Pages/Apps/Show.svelte`)

- Track pending confirmations by id: `confirmingIdentifierDelete` and
  `confirmingCredentialDelete` (hold the item id, `null` when idle).
- Actions:
  - `deleteIdentifier(identifier)` → `router.delete(identifier.delete_url, { preserveScroll: true })`
  - `deleteCredential(credential)` → `router.delete(credential.delete_url, { preserveScroll: true })`
- Identifier row (next to the existing Edit button, ~line 401): add a Delete button.
  When confirming, show inline "Delete this identifier? Cancel / Confirm"; if
  `credentials_count > 0`, the message notes how many credentials will be detached.
- Credential row (next to the existing Edit button, ~line 378): add a Delete button
  with the same inline confirm pattern.
- Match existing styling (`ghost px-2.5 py-1.5 text-[10px]`, rose accent for the
  destructive confirm, as used by the artifact Delete button).

## Testing (TDD, Feature tests)

- Identifier delete via web route: succeeds for a team member, removes the record,
  dispatches the `AppIdentifierDeleted` webhook.
- Credential delete via web route: succeeds, removes the record (full delete),
  dispatches the `CredentialDeleted` webhook.
- Authorization: non-member of the team → 403.
- Tenant isolation: credential belonging to another team → 404.

## Out of scope

- Polymorphic `credentialables` pivot rows are not FK-cascaded on identifier delete.
  This matches the current API `destroy` behavior; unchanged here to preserve parity.
- No soft deletes.
