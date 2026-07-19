# Tags System — Design

Date: 2026-07-19
Scope: `apps/platform`

## Goal

Add a lightweight, colored **tags** capability to four models — Build
(`BuildRecord`), App (`MobileApp`), `Credential`, and `Artifact`. Tags are stored
in a JSON column as a 2D array (array of tuples): each tuple is `[text, hexColor?]`,
where `text` is required and the hex color is optional. A shared `HasTags` trait
provides the behavior across all four models, with strict validation, a filtering
scope, dual serialization (web + API), and a reusable Svelte tag editor.

## Storage format

A nullable `json` column named `tags` on `build_records`, `mobile_apps`,
`credentials`, `artifacts`. The stored value is normalized to always-2-element
tuples:

```json
[["bug", "#ff0000"], ["urgent", null]]
```

- `tuple[0]` — tag text. Required, trimmed, max 50 chars.
- `tuple[1]` — hex color, or `null`. Accepted forms `#rgb` / `#rrggbb`, stored
  lowercased.

Normalization rules (applied on every write): trim text; drop empty-text tuples;
dedupe by text case-insensitively (first occurrence wins); lowercase hex; store
`null` color when absent so every tuple has a uniform 2-element shape.

Empty/absent tags serialize as `[]`.

## Database (migrations)

One alter-table migration per table, following the existing alter style
(`Schema::table(...)`, anonymous class, `json('tags')->nullable()`). Column placed
after an existing column (e.g. `after('...')` is a no-op on pgsql but kept for
readability parity with the codebase). Down migration drops the column.

Databases in play: **PostgreSQL** (dev/prod), **SQLite** (tests). Both support the
`json` column type and the JSON functions used by the filtering scope.

## Model trait — `App\Models\Concerns\HasTags`

New directory `app/Models/Concerns/` (mirrors the minimalist `app/Http/Concerns`
style). Applied to all four models. Each model also adds `'tags'` to its
`#[Fillable([...])]` attribute; `MobileApp` (which has no `casts()` method today)
gets one added.

Trait provides:

- `initializeHasTags()` — merges `'tags' => 'array'` into the model casts so the
  trait carries its own cast without each model repeating it.
- `hasTag(string $text): bool` — case-insensitive membership check.
- `addTag(string $text, ?string $color = null): static` — add/replace a tag by
  text, normalize, does **not** persist (caller decides `save()`), consistent with
  Eloquent setter semantics. (Naming/save semantics finalized during
  implementation; default is in-memory mutation.)
- `removeTag(string $text): static` — remove by text (case-insensitive).
- `syncTags(array $tags): static` — replace the whole set from raw tuples, running
  full normalization.
- `scopeWhereTag(Builder $query, string $text): Builder` — rows having a tag whose
  `[0]` equals `$text`.
- `scopeWhereAnyTag(Builder $query, array $texts): Builder` — rows having any of
  the given tag texts.

Normalization is delegated to `TagRules::normalize()` (single source of truth,
shared with the controller).

### Filtering scope (cross-database)

`scopeWhereTag` branches on the connection driver:

- **pgsql:**
  `EXISTS (SELECT 1 FROM jsonb_array_elements(tags::jsonb) el WHERE el->>0 = ?)`
- **sqlite / mysql:**
  `EXISTS (SELECT 1 FROM json_each(tags) el WHERE json_extract(el.value, '$[0]') = ?)`

A `null`/absent `tags` value must not error (guard so the `EXISTS` simply matches
nothing). `scopeWhereAnyTag` composes the same predicate under an `OR`/`IN` form.

## Validation — `App\Support\Tags\TagRules`

New value/support class under `app/Support/Tags/` (matches the existing
`app/Support` precedent for schema/rule helpers). No FormRequest — the codebase
validates inline via `$request->validate([...])`, so this class produces a rules
array to spread in.

- `TagRules::rules(string $key = 'tags'): array` returns strict rules:
  - `tags` → `['nullable', 'array']`
  - `tags.*` → `['array', 'min:1', 'max:2']`
  - `tags.*.0` → `['required', 'string', 'max:50']`
  - `tags.*.1` → `['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/']`
- `TagRules::normalize(?array $tags): array` — trim, drop empties, dedupe by text
  (case-insensitive), lowercase hex, coerce to 2-element tuples. Reused by the
  trait's `syncTags`/`addTag`.

Duplicate tags and empty text are eliminated by normalization; invalid hex is
rejected at the validation layer (strict), so it never reaches normalization.

## Endpoints — `App\Http\Controllers\TagsController`

A single web controller with one thin method per taggable, each using
route-model binding and the existing tenant guard
(`$this->authorizeTeam($request, $team)` / `abort_unless($model->team_id === $team->id, 404)`).
All delegate to a private `sync(Request, Team, Model)`:

1. Validate with `TagRules::rules()`.
2. `$model->syncTags($request->input('tags', []))`.
3. Wrap the `save()` in `DB::transaction`.
4. Fire the model's existing `WebhookEventType::*Updated` event via the injected
   `WebhookEventRecorder` **where such an event already exists** (App →
   `MobileAppUpdated`). For models without an existing "updated" event, skip the
   webhook in this iteration (noted as a follow-up rather than inventing new event
   types now).
5. `return back()->with('status', 'Tags updated.')`.

Routes (in the existing authenticated `accounts` group), each named:

- `PATCH /accounts/{team}/apps/{mobileApp}/tags` → `apps.tags.update`
- `PATCH /accounts/{team}/apps/{mobileApp}/builds/{buildRecord}/tags` → `builds.tags.update`
- `PATCH /accounts/{team}/apps/{mobileApp}/credentials/{credential}/tags` → `credentials.tags.update`
- `PATCH /accounts/{team}/apps/{mobileApp}/builds/{buildRecord}/artifacts/{artifact}/tags` → `artifacts.tags.update`

(Exact nesting mirrors the existing route structure; confirmed against
`routes/` during implementation.)

## API v1

Add tags to the four v1 resources and (optionally) allow setting them via the
existing v1 update endpoints where present:

- `'tags' => $this->tags ?? []` in `Api/V1/BuildRecordResource`,
  `CredentialResource`, `ArtifactResource`, `MobileAppResource`.
- Where a v1 update endpoint already exists for a model, spread `TagRules::rules()`
  into its validation and call `syncTags()`. No new v1 endpoints are created solely
  for tags in this iteration.

## Web serialization (Inertia)

The web UI does not use the API resources — controllers hand-build arrays. Add
`'tags' => $model->tags ?? []` and a `'tags_url' => route(...)` to the inline arrays
in `MobileAppController::show()` (the `app` array, `credentialData()` helper, the
builds map, and each build's artifacts map), plus any other web controller that
lists these entities.

## Frontend (Svelte 5 + Inertia)

Two reusable components under `resources/js/Components/`:

- `TagList.svelte` — read-only badges. Background from the tag's hex (fallback to a
  neutral default when `null`); text color auto-contrasts (luminance check) so
  labels stay readable on light/dark backgrounds.
- `TagEditor.svelte` — renders `TagList` badges with a remove (×) affordance, plus
  an add row (text input + optional native color input). Submits the full tag set
  via Inertia `router.patch(tags_url, { tags }, { preserveScroll: true })`.

Integrated into `Pages/Apps/Show.svelte` for the app itself and within the
credentials, builds, and artifacts sections. Each entity passes its own `tags` and
`tags_url` (already injected by the controllers).

## Testing

Feature tests (PHPUnit, existing style, SQLite) covering:

- Trait unit behavior: `addTag`/`removeTag`/`hasTag`/`syncTags` and normalization
  (trim, dedupe case-insensitive, hex lowercasing, empty-text dropping).
- Scope filtering: `whereTag` / `whereAnyTag` return the right rows on pgsql-style
  and sqlite (via the test DB), including the `null` tags guard.
- Endpoint happy path per model: PATCH updates tags and persists normalized value.
- Strict validation: invalid hex rejected, empty text rejected, `>50` char text
  rejected, duplicates collapsed.
- Tenant isolation: PATCH against a model from another team → 404.

## Out of scope (this iteration)

- New webhook event types for models lacking an "updated" event.
- Tag autocomplete / a team-wide tag vocabulary or color presets.
- Bulk tagging across multiple entities at once.
- Filtering UI on the frontend (the backend scope ships; wiring a filter control
  into pages is a follow-up).
