# Edit & delete project — design

## Goal

Allow editing a project (a `MobileApp`) from its detail page
(`/accounts/{team:slug}/apps/{mobileApp:slug}`). Today that page can edit
identifiers, credentials, and assets, but the app's own metadata cannot be
changed and the app cannot be deleted from the web UI.

## Scope

In scope:

- Edit `name` and `description`.
- Delete the project.

Out of scope:

- Editing `slug` (the page URL stays stable; no uniqueness/redirect concerns).
- Any change to identifiers, credentials, or assets (already handled).

## Backend

### Routes (`routes/web.php`)

Add to the `accounts.{team:slug}` group, alongside the existing
`apps.show`:

- `PATCH /apps/{mobileApp:slug}` → `MobileAppController@update`, name
  `accounts.apps.update`.
- `DELETE /apps/{mobileApp:slug}` → `MobileAppController@destroy`, name
  `accounts.apps.destroy`.

### Controller (`App\Http\Controllers\MobileAppController`)

Two new methods returning `RedirectResponse` (web flavour — the API
controller `Api\V1\MobileAppController` returns JSON and is left untouched):

- `update(Request $request, Team $team, MobileApp $mobileApp)`:
  - `authorizeTeam($request, $team)` (same guard as `store`/`show`).
  - Validate:
    - `name` — `required, string, max:255`
    - `description` — `nullable, string, max:2000`
  - `$mobileApp->update($data)`.
  - Redirect to `accounts.apps.show` for this team/app with
    `->with('status', 'Application updated.')`.
  - Slug is **not** part of the payload and is never recomputed.

- `destroy(Request $request, Team $team, MobileApp $mobileApp)`:
  - `authorizeTeam($request, $team)`.
  - `$mobileApp->delete()`.
  - Redirect to `accounts.apps.index` for the team with
    `->with('status', 'Application deleted.')`.

### Inertia payload (`show()`)

Extend the `app.urls` array with:

- `update` → `route('accounts.apps.update', [team, mobileApp])`
- `destroy` → `route('accounts.apps.destroy', [team, mobileApp])`

## Frontend (`resources/js/Pages/Apps/Show.svelte`)

- Add an **Edit** button in the page header, next to the existing
  "Add credential / Add identifier" button. Same button styling as the rest
  of the page.
- Add an **Edit application** modal, matching the existing identifier /
  credential modals (`modal-backdrop` / `modal`, header, body, footer):
  - `useForm({ name, description })` seeded from `app.name` / `app.description`
    when opened.
  - Fields: `name` (`input`, required) and `description` (`textarea`,
    optional). Show field errors via `form.errors`.
  - Submit calls `form.patch(app.urls.update, { preserveScroll: true,
    onSuccess: closeEdit })`.
  - Footer: Cancel + Save changes (disabled while `processing`).
- Delete lives in a danger section at the bottom of the same modal:
  - Default state: a "Delete application" danger button.
  - Clicking it swaps to an inline confirmation *within the modal*
    ("Are you sure? This can't be undone.") with a Confirm delete button and a
    way to back out. No native `confirm()`.
  - Confirm calls `router.delete(app.urls.destroy)` — server redirects to the
    apps index.

## Tests (feature, Pest)

- `update` changes `name`/`description` and redirects to `apps.show`.
- `update` rejects an empty `name` (validation error).
- `update` by a user not on the team returns 403.
- `update` leaves `slug` unchanged even if a slug field is submitted.
- `destroy` deletes the app and redirects to `apps.index`.
- `destroy` by a user not on the team returns 403.

## Implementation approach

Test-driven: write the failing feature tests first, then the routes +
controller methods, then the Svelte UI.
