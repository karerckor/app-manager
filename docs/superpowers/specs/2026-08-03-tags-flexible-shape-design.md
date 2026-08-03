# Tags — Flexible Shape and Animation — Design

Date: 2026-08-03
Scope: `apps/platform`
Supersedes the storage section of `2026-07-19-tags-system-design.md`

## Goal

Loosen the tag tuple from a fixed `[text, color]` pair into a variable-length list
that carries an optional third field, `animation`. A tag may be stored as
`["bug"]`, `["bug", "#f00"]`, `["bug", null, "pulse"]`, or
`["bug", "#f00", "pulse"]`. The animation value is one of five whitelisted tokens
and drives a CSS class on the rendered badge.

This spec covers the data model only. Tag-based search and filtering in the API is
a separate spec (`2026-08-03-query-filters-api-design.md`), which depends on the
`whereAllTags` and `whereTagLike` scopes added here.

## Storage format

The `tags` JSON column stays on all five taggable tables (`mobile_apps`,
`build_records`, `credentials`, `artifacts`, `notification_destinations`). What
changes is the canonical shape written into it: trailing `null`s are dropped, so
tuples are 1, 2, or 3 elements wide.

```
["bug"]                      text only
["bug", "#f00"]              text + color
["bug", null, "pulse"]       text + animation, no color
["bug", "#f00", "pulse"]     all three
```

An animation without a color must keep the `null` placeholder at index 1 —
positional tuples have no other way to express a gap. This is the one case where a
`null` survives compaction.

### No data migration

`App\Casts\Tags` runs `TagRules::normalize()` on both `get` and `set`. Existing
rows stored as `["bug", null]` therefore read back as `["bug"]` with no schema or
data change, and compact in the database the next time the record is saved. No
backfill migration, no new migration file at all.

## Animation enum — `App\Enums\TagAnimation`

New string-backed enum. Canonical values are lowercase, matching the existing
convention that hex colors are lowercased on write:

| case | value | source |
|---|---|---|
| `Pulse` | `pulse` | Tailwind |
| `Ping` | `ping` | Tailwind |
| `Flash` | `flash` | animate.css |
| `Tada` | `tada` | animate.css |
| `HeartBeat` | `heartbeat` | animate.css |

Plus one helper:

```php
public static function tryFromLoose(string $value): ?self
```

Case-insensitive lookup, so a client sending `heartBeat` (the animate.css spelling)
is accepted and canonicalized to `heartbeat`. `tryFrom()` alone would reject it.

The enum deliberately does **not** expose a `cssClass()` method. Tags are never
rendered server-side — every consumer (API resources, Inertia payloads, the Svelte
components) passes the raw tuple through. The token-to-class mapping therefore
lives only where it is used, in JavaScript. A PHP `cssClass()` would be dead code.

## CSS class mapping — `resources/js/lib/tagAnimations.js`

A single exported map, the only place a token becomes a class:

| token | class | provided by |
|---|---|---|
| `pulse` | `animate-pulse` | Tailwind |
| `ping` | `animate-ping` | Tailwind |
| `flash` | `tag-anim-flash` | ours |
| `tada` | `tag-anim-tada` | ours |
| `heartbeat` | `tag-anim-heartbeat` | ours |

The module exports the map plus a `animationClass(token)` lookup that returns `''`
for `null`/unknown tokens, so callers never guard.

Shape: `{ token: { label, class } }` — the label is what the editor's chips
display, so the picker builds itself from the map rather than repeating the list.

## CSS — `resources/css/app.css`

animate.css is not a dependency and is not being added: three animations do not
justify ~70KB of non-tree-shakeable CSS. Instead three `@keyframes` and three
utility classes are copied from animate.css (MIT, attribution comment retained)
into `resources/css/app.css`:

```css
@keyframes tag-flash { ... }
@keyframes tag-tada { ... }
@keyframes tag-heartbeat { ... }

.tag-anim-flash     { animation: tag-flash 1s infinite; }
.tag-anim-tada      { animation: tag-tada 1s infinite; }
.tag-anim-heartbeat { animation: tag-heartbeat 1.3s infinite; }
```

The file already carries a `prefers-reduced-motion` block that forces
`animation-duration: 0.01ms` and `animation-iteration-count: 1` globally, so these
classes are neutralized for reduced-motion users with no extra work. Tailwind's
`animate-pulse` / `animate-ping` are covered by the same block.

## Validation — `App\Support\Tags\TagRules`

`MAX_TEXT_LENGTH` and `HEX_REGEX` are unchanged. `rules()` widens the tuple and
gains a third field:

```php
$key       => ['nullable', 'array'],
"{$key}.*" => ['array', 'min:1', 'max:3'],
"{$key}.*.0" => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
"{$key}.*.1" => ['nullable', 'string', 'regex:'.self::HEX_REGEX],
"{$key}.*.2" => ['nullable', 'string', <closure>],
```

The third field uses a closure rather than `Rule::enum(TagAnimation::class)`
because `Rule::enum` is case-sensitive and would reject `heartBeat`. The closure
fails the attribute when `TagAnimation::tryFromLoose($value) === null`, keeping the
enum the single source of truth for the whitelist.

### `normalize()`

Same contract as today — trim text, drop empty-text entries, dedupe by text
case-insensitively with first-occurrence-wins, lowercase hex, accept legacy plain
string entries — with two additions:

1. Animation is resolved through `TagAnimation::tryFromLoose()` and stored as the
   canonical lowercase value, or `null`.
2. The result is compacted rather than padded to a fixed width:

```
animation !== null  →  [text, color, animation]   (color may be null)
color !== null      →  [text, color]
otherwise           →  [text]
```

Invalid hex and unknown animations are rejected at the validation layer, so they
normally never reach `normalize()`. Reached through a non-validated path (a seeder,
a factory), an unknown animation is dropped to `null`; color keeps today's
behavior of being lowercased as-is without a hex check, so that path is unchanged
by this spec.

Return type in the docblock becomes
`list<array{0: string, 1?: string|null, 2?: string|null}>`.

## Model trait — `App\Models\Concerns\HasTags`

- `addTag(string $text, ?string $color = null, TagAnimation|string|null $animation = null): static`
  — third parameter added; accepts the enum or a loose string, normalized as above.
- `tagList()` docblock updated to the variable-length shape.
- `hasTag()` and `removeTag()` need no change — both already read `$tag[0] ?? ''`.

Two new scopes:

- `scopeWhereAllTags(Builder $query, array $texts): Builder` — rows carrying every
  one of the given tag texts. Reuses the existing `applyTagPredicate()` with
  `boolean: 'and'`, mirroring how `scopeWhereAnyTag` uses `'or'`. Required by the
  API `tags_mode=all` parameter in the filters spec.
- `scopeWhereTagLike(Builder $query, string $pattern): Builder` — rows with a tag
  whose text partially matches, case-insensitively. This lifts the raw SQL that is
  currently inlined in `DashboardController::index` (lines ~108-118) into the trait
  alongside the exact-match predicate:
  - **pgsql:** `EXISTS (... jsonb_array_elements(...) tag WHERE tag->>0 ILIKE ?)`
  - **sqlite:** `EXISTS (... json_each(...) tag WHERE json_extract(tag.value, '$[0]') LIKE ? COLLATE NOCASE)`
  - **other drivers:** fall back to `whereLike('tags', ?)` on the raw JSON

  The first two guard a `null` column with `COALESCE(..., '[]')`, matching
  `applyTagPredicate`. `DashboardController` is then rewritten to call the scope,
  deleting its copy.

  The third branch is imprecise — matching the raw JSON means a pattern could hit a
  color or animation value, not just tag text. It is carried over from the
  `DashboardController` code being replaced and is unreachable on the drivers this
  project supports (PostgreSQL in dev/prod, SQLite in tests). Anywhere this spec or
  the filters spec claims that search touches tag text only, that guarantee is
  scoped to those two drivers.

## Cast — `App\Casts\Tags`

No behavioral change; it already delegates both directions to
`TagRules::normalize()`. Only the `@implements` docblock is updated to the
variable-length shape.

## Frontend — `TagEditor.svelte`

The popover gains an animation row beneath the existing color palette: five chips
built from `tagAnimations.js` plus a "None" option, selected the same way as a
palette swatch (`aria-pressed`, single active choice).

- `working` maps to 3-wide tuples internally, and submits compacted tuples so the
  payload matches what the server will store.
- The rendered badge gets `class={... animationClass(tag[2])}`.
- `addValue()` takes the animation as a third argument; `addSuggestion()` forwards
  `tag[2]`.

The component stays in legacy Svelte mode (`export let` / `$:`). It persists
through `router.patch` with local `saving` state rather than `useForm`, so the
known legacy-mode problem with Inertia form re-rendering does not apply here, and
keeping it legacy holds the diff to the animation feature itself.

## Call sites that must become 3-aware

These currently coerce tuples to exactly two elements and would silently discard
animation:

| location | what it does |
|---|---|
| `app/Support/Apps/AppContext.php:206` | aggregates the team-wide tag suggestion list |
| `app/Http/Controllers/AppBuildPageController.php:84` | builds `filterTags` for the builds page |
| `resources/js/Pages/Apps/Builds/Index.svelte:41,112` | tag filter options + suggestion list |
| `resources/js/Pages/Apps/Builds/Show.svelte:94` | suggestion list |
| `resources/js/Pages/Apps/Identifiers/Show.svelte:175` | suggestion list |

`app/Support/Audit/AuditEntryPresenter.php:473-480` is already null-safe but reads
only text and color; it is extended so the audit diff shows an animation change.

Verified safe without changes, because they read `$tag[0]` only or pass the tuple
through untouched: the four `Api/V1` resources, `BuildPresenter`,
`CredentialPresenter`, `NotificationTemplateRenderer::formatTags`,
`NotificationEventCatalog`, `WebhookEventFilterContext`,
`WebhookSubscriptionMatcher`.

## Testing

- `normalize()` compaction for all four shapes, including the animation-without-color
  case that must retain the `null` placeholder at index 1.
- `heartBeat` is accepted and canonicalized to `heartbeat`; an unknown animation is
  rejected by `TagRules::rules()` with a 422.
- A tuple of four elements is rejected (`max:3`).
- A row persisted in the old `[text, null]` form reads back as `[text]` — the
  no-migration claim, asserted rather than assumed.
- `addTag()` with an animation, and with a loose-cased animation string.
- `whereAllTags` matches only rows carrying every text; `whereAnyTag` behavior
  unchanged.
- `whereTagLike` matches partial text case-insensitively and does not match on
  color or animation values.
- `DashboardController` search still matches tag text after switching to the scope.
- The existing tag test suite stays green unmodified, except where it asserts the
  padded two-element storage shape.

## Out of scope

- Tag-based search and filtering in the API — `2026-08-03-query-filters-api-design.md`.
- Per-team tag vocabulary or preset animations.
- Animation on the read-only badge in the audit log timeline (the diff shows the
  value as text; animating a historical record would be noise).
- Exposing animation through the `libs/cli` tag commands.
