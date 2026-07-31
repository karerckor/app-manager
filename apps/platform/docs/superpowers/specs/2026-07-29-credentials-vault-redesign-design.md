# Credentials Vault page redesign

**Date:** 2026-07-29
**Route:** `GET /accounts/{team}/credentials` → `CredentialVaultController::__invoke` → `Credentials/Index.svelte`
**Direction:** "Ledger" — a dense, aligned table with a sticky header, replacing four stacked card layers.

## Why

Every piece of information the page needs is already on screen. The problems are structural:

1. **The same numbers appear three times.** The amber banner says "2 expired · 0 expiring", four stat cards repeat "251 / 0 / 2 / 0", and the list header repeats "251 credentials in Demo Team".
2. **~620px of chrome before the first row.** Banner + stat cards + filter card + list header are four separate white layers. On a 1080px-tall window roughly two credentials are visible.
3. **Three of four metrics are zero** but carry the same visual weight as 251, and none of them is clickable even though a status filter already exists.
4. **`USED BY` is printed 251 times.** A column label is masquerading as a field caption.
5. **Rows are not aligned to a header**, so 251 records read as loose blocks rather than a scannable column.
6. **The healthy state shouts.** Every valid credential gets an emerald pill, so ~200 rows announce success and the two rows that need action do not stand out.

## Job of the page

Find the credential you need, and see at a glance which ones are rotting. Two modes: lookup (search → filter → act) and triage (what expired, what is unattached, what has no file). Audience: release engineers who already know what a provisioning profile is.

## Scope

**In scope**
- `resources/js/Pages/Credentials/Index.svelte` — full restructure
- `resources/js/Components/TagEditor.svelte` — one new prop (`quiet`)
- `resources/css/app.css` — mono font token, shared grid template, table row/head component classes
- `app/Http/Controllers/CredentialVaultController.php` — two new filter values, one new stat, one new sort param
- `tests/Feature/CredentialVaultTest.php` — coverage for the new filters and sort

**Out of scope**
- Migrations, changes to `Credential`, or any change to what is stored
- `VaultCredentialFormModal.svelte` (the create/edit modal keeps its current design)
- Rolling the pattern out to Apps / AuditLog / ApiKeys — deliberately deferred, see Follow-ups
- Grouping by application, and master–detail split view — both were considered and rejected (see Rejected alternatives)

## Design tokens

### Color — inherited, nothing invented

This is one page inside an existing product, so the palette comes from `app.css` unchanged.

| Name | Value | Role |
|---|---|---|
| `page` | `#f6f7fb` | page ground (existing `body`) |
| `ink` | `#020617` | credential names, numbers |
| `rule` | `#eef2f6` | table grid hairlines |
| `indigo` | `#4f46e5` | the single interactive accent |
| `amber` | `#f59e0b` / `#b45309` | expiring, missing file, unattached |
| `rose` | `#e11d48` / `#be123c` | expired, destructive |
| `slate-400` | `#94a3b8` | the machine/meta layer |

**One substantive change: emerald stops signalling status.** Colour that signals *state* is reserved for what needs action. A credential with 284 days of runway gets no pill and no "Valid" label. The first status colour to appear on the page is the amber of a credential with 12 days left.

Three things are deliberately **not** covered by that rule, because none of them signals state:

- the "Encrypted at rest" badge, which is a property of the vault rather than of any row
- **the emerald-tinted Android platform mark.** It sits in the leftmost column at the 50 tint, while status lives in the right-hand Validity column at the 500 tint — a full-width `rose-500` flood or an `amber-500` stub. They neither compete for attention nor occupy the same space, and the mark answers "which platform", never "is this healthy".
- **user-chosen tag colours.** `TagEditor` lets people pick from a palette that includes emerald; a tag reading "Secret synced from Expo" in green is the user's data, not our signal.

The first draft of this rule said simply "emerald leaves the rows", which read as absolute and collided with the platform mark. The rule was always about status pills claiming "Valid"; it is narrowed here to say what it meant.

### Typography — two voices

- **Inter** (existing) for human language.
- **Mono** for machine data: `--font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace`. System stack, zero webfont cost, resolves to SF Mono on macOS. Add `font-variant-numeric: tabular-nums` so day counts align down the column.

Mono carries: bundle identifiers, file names, day counts, byte sizes, pagination ranges, page numbers. Today `ai.appfront.bhm-hienies` is set in the same face as the prose subheading; a bundle id is code, not prose.

| Role | Spec |
|---|---|
| H1 | Inter 800, 24px, `tracking-tight` (down from 30px — the H1 no longer carries the band alone) |
| Column header | Inter 700, 10px, `tracking-[0.1em]`, uppercase, `slate-400` |
| Credential name | Inter 600, 13px |
| Row meta | Inter 400, 11px, `slate-400` |
| Machine data | Mono 500, 11px |

10px is the floor. The current file uses `text-[9px]` in places; do not carry that over.

## Layout

```
max-w-[1240px] mx-auto px-5 sm:px-8 lg:px-10 py-8 lg:py-10

┌ header band ──────────────────────────────────────────────────┐
│ WORKSPACE SECURITY                                             │
│ Credentials Vault                          [＋ Add credential] │
└────────────────────────────────────────────────────────────────┘
┌ chip rail — no card, sits on page ground ──────────────────────┐
│ (All 251) (Expired 2) (Expiring 0) (Unattached 12)             │
│ (No file 0) (No expiry 37)                                     │
└────────────────────────────────────────────────────────────────┘
┌ toolbar — no card wrapper ─────────────────────────────────────┐
│ [⌕ Search credentials, apps, identifiers…] [Type ⌄] [App ⌄]    │
│ [Sort ⌄]                                              Clear    │
└────────────────────────────────────────────────────────────────┘
┌ card, p-0, overflow-clip ──────────────────────────────────────┐
│ Team credentials  251 in Demo Team        ✓ Encrypted at rest   │
├ sticky ────────────────────────────────────────────────────────┤
│ CREDENTIAL              USED BY        VALIDITY                │
├────────────────────────────────────────────────────────────────┤
│ ▪ Expo iOS provisioning profile  Bob's Hienies   ▓▓▓░ −76d     │
│   Provisioning profile · expo-provisioning-profile.mobile…     │
│   (Imported from Expo) (Secret synced from Expo)               │
├────────────────────────────────────────────────────────────────┤
│ …                                                              │
├────────────────────────────────────────────────────────────────┤
│ 1–18 of 251                        [Prev] 1 2 3 [Next]         │
└────────────────────────────────────────────────────────────────┘
```

### Header band

Eyebrow + H1 + primary action. The prose subheading ("Signing certificates, APNs keys, Firebase configuration, and service accounts for the entire team.") is removed — it enumerates what the type column already shows on every row.

When `canManage` is false, the "Read-only access" pill sits where the button was, as quiet slate text rather than a bordered pill.

`flash.status` keeps its current emerald banner, unchanged, directly under the band.

### Chip rail

Replaces both the amber banner and the four stat cards. Each chip is a filter that sets `status` and navigates through the existing `visitFilters` path.

| Chip | `status` value | Count source | Styling |
|---|---|---|---|
| All | `''` | `stats.total` | indigo when active |
| Expired | `expired` | `stats.expired` | rose fill when count > 0 |
| Expiring | `expiring` | `stats.expiring` | amber fill when count > 0 |
| Unattached | `unattached` | `stats.unattached` | amber fill when count > 0 |
| No file | `missing_file` | `stats.missing_files` | amber fill when count > 0 |
| No expiry | `never` | `stats.never` | neutral |

**A chip whose count is 0 renders dimmed (`slate-300` text, `slate-100` border) and is `disabled`.** Zeros stop competing with 251 for attention, and a filter that can only return an empty list is not offered.

The amber "rotation needs attention" banner is deleted. Its content is the rose "Expired 2" chip, which is permanently visible and immediately clickable — one strong red element instead of a banner plus a card plus a pill. The banner's "Review now" button is likewise absorbed: clicking the chip *is* reviewing now.

### Toolbar

Search + type select + app select + **sort select**. The status select is removed — the chip rail owns status now, and having both would be two controls for one piece of state. The card wrapper is dropped; fields sit directly on the page ground, removing one layer of white. "Clear" appears only when a filter is active, as it does today.

Sort options: `Recently updated` (default, current behavior) and `Soonest expiry`.

### Table

A `div`-based CSS grid carrying explicit ARIA table roles: `role="table"` / `rowgroup` / `row` / `columnheader` / `cell`. Users genuinely scan validity down a column, so table semantics are earned; a `div` grid keeps the responsive collapse and the expandable region tractable, and the roles keep the accessibility tree tabular even when the visual layout stacks.

**The grid template is defined once in `app.css`**, so the header row and the data rows cannot drift apart:

```css
@layer components {
    .vault-grid { @apply grid gap-3 lg:grid-cols-[minmax(0,1fr)_172px_118px_104px]; }
}
```

Below `lg` this collapses to a single column, the header row is hidden, and each row reads as a stacked card.

Sticky header: `sticky top-0 z-10` on the header row. **The table card must use `overflow-clip`, not `overflow-hidden`** — `overflow: hidden` establishes a scroll container, which makes `position: sticky` stick to that container instead of the viewport and silently does nothing. `overflow: clip` still rounds the corners without creating a scroll container.

### Row anatomy

Two lines, ~58px; three lines when tags are present, ~76px.

- **Cell 1 — Credential.** 28px platform mark, then:
  - Line 1: name, Inter 600 13px, `truncate`. This is the expand toggle (a `button` with `aria-expanded` / `aria-controls`).
  - Line 2: `{type label} · {N} secure fields · {file name in mono}`. When there is no file, `no secure file uploaded` in amber replaces the file name — the only amber that appears in this line.
  - Line 3, only when tags exist: `TagEditor` in `quiet` mode.
- **Cell 2 — Used by.** App name (Inter 600 11px, truncate) over identifier (mono 10px, `slate-400`). More than one attachment renders `+N more` in mono under the first. No attachments renders `Unattached` in amber. The per-row `USED BY` eyebrow is gone.
- **Cell 3 — Validity.** The runway meter (below).
- **Cell 4 — Actions.** Icon buttons, revealed on hover/focus, plus a chevron that reflects expansion state.

**The platform mark carries information.** Today every row shows the same `KeyRound` glyph, so the icon is decoration repeated 251 times. It becomes three marks: dark slate for iOS, emerald-tinted for Android, neutral slate for anything that is neither or both.

Resolution order, applied in this exact sequence so the result is deterministic:

1. If the credential has attachments and **all** of them share one platform, use that platform.
2. Otherwise consult `credentialTypes` for this type: if `platforms` contains exactly one entry, use it.
3. Otherwise (mixed attachments, or a type supporting both platforms such as a Firebase service account) use the neutral mark.

Rule 1 before rule 2 matters: a type may support both platforms while this particular credential is only ever used by iOS identifiers, and the row should say iOS.

The violet "Shared" badge is removed. It is derivable from the Used-by cell, which already shows `+N more`.

The bordered box around the action icons is removed. On 251 rows it is 251 extra rectangles; the icons live directly in the row.

## Signature: the validity runway

The thesis of a credential vault is that **everything in it dies on a schedule**. That deserves to be shown, not narrated. A 4px track with a single denominator of **90 days**, so bars are comparable between rows, with the exact figure beside it in mono — the bar is for the glance, the number is for the decision.

**`expiration.status` from the server is the authority for which row applies; `days` only drives the bar width and the label.** The server already classifies `days < 0` as `expired`, `days <= 30` as `expiring`, otherwise `valid` — so day 0 is `expiring`, not `expired`, and the frontend must not re-derive this.

| `expiration.status` | Track | Fill | Label |
|---|---|---|---|
| `never` | dashed `slate-200` hairline, no track | — | `no expiry`, `slate-400` |
| `valid`, days ≥ 90 | `slate-100` | 100%, `slate-300` | `+284d`, `slate-500` |
| `valid`, days < 90 | `slate-100` | `days/90`, `slate-400` | `+62d`, `slate-500` |
| `expiring` | `slate-100` | `days/90`, `amber-500` | `+12d`, `amber-700` |
| `expired` | `rose-100` | **100%, `rose-500`** | `−76d · expired`, `rose-700` |

Fill width is `clamp(days / 90, 0, 1) * 100%` for the live states.

**Why expired is full rather than empty.** The first draft of this spec drew expired as an *empty* rose track with a small cap at the left edge — "the runway is spent". Built and viewed against real data, that reads as *nothing*, and it inverts the severity ordering: a credential 76 days dead looked calmer than one with 10 days left, because the latter at least had a visible amber stub. Emptiness reads as absence, not as exhaustion. A fully-flooded rose track is unambiguous and restores the correct visual order: **full rose (dead) > short amber (dying) > long grey (healthy)**.

The label keeps today's special-cased copy for the near cases rather than a bare number: `expires today` at day 0 and `expires tomorrow` at day 1, both amber. Everything else uses the signed mono day count.

The tone darkens as the fill shrinks (`slate-300` → `slate-400` → `amber-500`), so urgency reads from color and length together. Everything above 30 days stays colorless.

`days` comes from the existing `credential.expiration.days`; no new data is needed. Track width transitions respect `prefers-reduced-motion`.

## States

### Hover, focus, touch

Action icons and the tag editor's `＋`/`✕` affordances are hidden by default (`opacity-0`) and revealed on:
- `group-hover` — the row is the `group`
- `group-focus-within` — so keyboard users reach them
- `@media (hover: none)` — always visible, so they are reachable on touch devices

All four are mandatory. Hover-only would make the page unusable by keyboard and on tablets. Every icon button keeps a visible `focus-visible` ring.

### Expanded row

Clicking the credential name expands a full-width region below the row containing what the dense row cannot hold:

- Every attachment as a link (not just the first two), each with app name, identifier, and build-profile badge
- File name, **size** (`12 KB`), and download link — size lives here rather than in the row
- Secure field names, and `Show passwords` when `reveal_url` is present
- `Updated {relative time}`

Revealed secrets render inside this region, keeping the existing flow: password confirmation modal → `POST reveal_url` → 60-second auto-hide → copy buttons. Only the styling changes.

Two consequences of nesting the reveal panel inside a collapsible region, both of which must be handled:

- **Revealing opens the row.** Otherwise a user could confirm their password and have the secrets render into a collapsed row, seeing nothing.
- **Collapsing the row hides the secrets.** Collapsing reads as "put this away", so it must clear the revealed state rather than merely hiding it from view — otherwise re-expanding within the 60-second window re-displays the secret with no further confirmation. The explicit "Hide" button stays, for hiding without collapsing.

The delete confirmation stays **outside** this region, as a direct child of the row: a credential must be deletable without opening it.

### Empty states

Copy is rewritten to direct rather than sell.

- **Filtered, no results:** "No credentials match these filters" / "Try a different search, or clear the filters." + `Clear filters`
- **Vault empty:** "Nothing in the vault yet" / "Add a signing certificate, keystore, or service account to use it in builds." + `Add first credential`
- **Vault empty, no app identifiers yet:** "Nothing in the vault yet" / "Create an application identifier first — credentials attach to identifiers." The add button stays disabled, as it does today.

### Responsive

Below `lg` the grid collapses to one column, the column header row is hidden, and each row stacks: name → meta → tags → used by → validity → actions. Actions are always visible (touch). The toolbar wraps to two rows; the chip rail wraps naturally.

## Backend changes

`CredentialVaultController::__invoke`, no migrations, no model changes.

**New filter values.** `$status` whitelist gains `unattached` and `missing_file`:

```php
->when($status === 'unattached', fn (Builder $q) => $q
    ->whereDoesntHave('appIdentifiers')
    ->whereDoesntHave('profiles'))
->when($status === 'missing_file', fn (Builder $q) => $q
    ->whereDoesntHave('media', fn (Builder $m) => $m->where('collection_name', 'file')))
```

`missing_files` already has a stat but no filter, so today its count is a dead number.

**New stats.** `unattached` (mirroring the filter above) and `never` (`whereNull('expires_at')`), so every chip in the rail has a count.

**New sort.** `$sort` accepts `updated` (default) and `expiry`:

```php
->when($sort === 'expiry',
    fn (Builder $q) => $q->orderByRaw('expires_at IS NULL ASC, expires_at ASC'),
    fn (Builder $q) => $q->latest('updated_at'))
```

On Postgres `expires_at IS NULL ASC` sorts `false` before `true`, putting dated credentials first and undated ones last. `$sort` is whitelisted the same way `$status` already is — `in_array($request->string('sort'), ['updated', 'expiry'], true)` — so an unknown value silently falls back to `updated` rather than reaching `orderByRaw`. Add `sort` to the `filters` prop so the select can round-trip its value.

## Frontend component changes

`TagEditor.svelte` gains one prop:

```js
export let quiet = false;
```

When `quiet`, the per-tag `✕` button and the dashed `＋ Tag` button get `opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100`. Nothing else about the component changes — it already renders 11px chips, which is the right density.

## Accessibility floor

- Visible `focus-visible` ring on every interactive element, including the hover-revealed icons
- Action icons reachable by keyboard via `group-focus-within`, and always visible on touch
- Expand toggle uses `aria-expanded` + `aria-controls`; the expanded region has a matching `id`
- Chip rail chips are real `button`s; zero-count chips are `disabled`, not just dimmed
- Validity conveys state through **label text as well as color** — `−76d · expired` reads correctly without color vision. The bar is decorative reinforcement, `aria-hidden`
- Every icon-only button keeps its `aria-label` and `title`
- Track transitions respect `prefers-reduced-motion`
- Type floor of 10px; no `text-[9px]`

## Implementation traps in this codebase

1. **`@layer base { button { … } }` in `app.css` paints every `button` indigo with padding and shadow.** Icon buttons must reset with `bg-transparent p-0 shadow-none` (the current file already does this in places — carry it through consistently, and prefer a `.icon-btn` component class over repeating the resets).
2. **`overflow-hidden` breaks the sticky header.** Use `overflow-clip` on the table card.
3. **Define `grid-template-columns` once** as `.vault-grid` in `app.css`. Repeating the template string on the header row and the data rows is how columns silently drift apart.
4. **`@layer components` classes lose to utilities in the markup**, which is what we want — but avoid adding padding to both `.vault-row` and a `px-*` utility on the same element.

## Testing

Extend `tests/Feature/CredentialVaultTest.php` (PHPUnit class style, `RefreshDatabase`, `AssertableInertia`):

- `status=unattached` returns only credentials with neither `appIdentifiers` nor `profiles`
- `status=missing_file` returns only credentials with no `file` media
- `stats.unattached` and `stats.never` are correct
- `sort=expiry` orders soonest-expiry first and places `expires_at IS NULL` last
- `sort` round-trips into the `filters` prop
- An invalid `status` or `sort` value falls back to the default instead of erroring
- Existing vault tests keep passing unchanged — the props contract is additive

Manual verification: load the real page at `/accounts/demo-users-team/credentials` and screenshot it, since the redesign is visual and the seeded demo team has 251 credentials across every state.

## Rejected alternatives

**Group by application.** The `USED BY` column would disappear into group headers, which matches the "what does app X need for release" mental model and would surface unattached credentials as a real group. Rejected because pagination fights it: 18 records per page cuts groups in half, and a credential attached to three applications would appear three times. Fixing that means restructuring the server-side query around applications rather than credentials — too large for a tidy-up.

**Master–detail split view.** A narrow dense list plus a detail panel would give tags, files, and secrets room to breathe. Rejected as too large an interaction change: it needs deep-linking to the selected record, a second layout mode for mobile, and it removes the ability to compare two records by eye. At `max-w-[1240px]` both panes end up cramped.

## Accepted risks

1. **The healthy state is silent** — no emerald, no "Valid" on ~200 rows. On a triage page, color must mean "needs action". The risk is that absence of color reads as unfinished. Reversible in one place if it lands badly: a very quiet emerald dot.
2. **Actions are hover-revealed.** Quieter rows at the cost of discoverability, mitigated by the keyboard and touch rules above. The alternative — a single `⋯` menu — adds a click to every action.
3. **File size moved into the expanded row.** Saves a column; the row keeps the file name, which is the part people search for.

## Follow-ups (not now)

- Extract `DataTable`, `FilterChips`, `ValidityMeter`, and `PageHeader` into `Components/` and pull Apps / AuditLog / ApiKeys onto the same pattern. Deliberately deferred: doing it in the same change would widen the diff and risk regressions on pages that work today.
- Filter chips currently replace the whole `status` value, so they cannot combine (expired **and** unattached). Multi-select status would need the controller to accept an array.
