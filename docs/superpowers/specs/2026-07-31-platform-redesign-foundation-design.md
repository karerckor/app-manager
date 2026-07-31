# Platform redesign — foundation

**Date:** 2026-07-31
**Scope:** design tokens, font pipeline, theme switching, primitive component library, and one pilot page (Credentials Vault) in `apps/platform`.

## Problem

The console's pages do not look like one product. The differences are structural, not cosmetic.

**Two page skeletons.** Five pages (`Dashboard`, `Credentials/Index`, `AuditLog/Index`, `Integrations/Index`, `Tools/PushNotifications`) render through `Components/WorkspacePage.svelte`, which sets `h1` to `text-2xl font-extrabold` with a `mb-5` header and has no slot for a description. Five more (`ApiKeys/Index`, `Team/Index`, `Billing/Index`, `Profile/Edit`, `Apps/AppLayout`) bypass it and hand-roll a header on `AppShell`, using `text-3xl font-bold`, `mb-8`, and a description paragraph. The missing description slot is what causes the bypass, so the split is a design gap, not carelessness.

**Three container widths.** `1240px` on most pages, `1040px` on `Profile/Edit`, `960px` on `Billing/Index`.

**No primitives.** Five shared components carry 7,804 lines of pages. Each page reinvents the table head (four implementations at three different type sizes — `text-[9px]`, `text-[10px]`, and the `.vault-head` class), the row grid, badges, empty states, stat tiles, and the filter bar.

**Type scale has no system.** `text-[8px]`, `[9px]`, `[10px]`, `[11px]`, `[12px]`, `[13px]` coexist with `xs/sm/base/lg/xl/2xl/3xl`. `text-[9px]` alone appears 58 times.

**Radius has no rule.** `rounded-md` 34, `lg` 77, `xl` 141, `2xl` 46, `full` 95, `3xl` 2.

**Two brand hues.** `indigo-600` (the base button), `indigo-500` (14 fills), `violet-600/700` ("Manage EAS"), and `violet-400` for links on the `Auth` pages, which read as a different product.

**Disabled primary buttons read as enabled.** `disabled:opacity-50` over `bg-indigo-600` produces a lavender fill. On `Team/Index` ("Save name", "Save default") and `Tools/PushNotifications` ("Send notification") the disabled control looks like the page's main call to action.

**Destructive actions are inconsistent.** "Revoke", "Disconnect", "Delete", and "Remove selected" are bare rose text, while the `.danger` class defined in `app.css` is almost unused. `ApiKeys/Index` confirms revocation with `window.confirm` while every other flow uses a modal.

**No fonts are loaded.** `app.css` declares `--font-sans: 'Inter', 'Instrument Sans', …`, but `resources/views/app.blade.php` has no `@fonts` directive, no `<link>`, and no `@font-face`. The interface currently renders in the operating system's UI font.

**No keyboard focus on buttons.** The base `button` rule in `app.css` sets no focus style. `:focus-visible` appears only on `.icon-btn` and the log filter input in `Apps/Builds/Show.svelte`.

### Root cause

`app.css` styles elements, not components:

```css
button { @apply inline-flex cursor-pointer ... bg-indigo-600 px-4 py-2.5 ... text-white shadow-sm ... }
input, select, textarea { @apply w-full rounded-xl border border-slate-200 ... }
```

Every button in the application is a primary button until something overrides it. The codebase is therefore full of counter-styling: `.ghost` cancels with `bg-transparent text-slate-600 shadow-none`, `.icon-btn` must declare `bg-transparent p-0 … shadow-none`, and the dark log filter in `Apps/Builds/Show.svelte:202` overrides the global input with seven classes. This is the mechanism that produces divergence; individual pages are downstream of it.

## Decisions already taken

| Question | Decision |
|---|---|
| Depth of the redesign | New visual identity plus a design system, not a consistency pass over the current look |
| Visual direction | **Blueprint** — technical-drawing language, cyanotype blue, condensed uppercase structure, monospace as a first-class UI face |
| Dark theme | In scope, built on semantic tokens |
| Font delivery | Self-hosted through npm and Vite. No CDN — the product is self-hostable (there is an `Installer`) and external fonts would break a closed network |
| Runway denominator | Fixed 365-day window, clamped |

## Visual direction: Blueprint

The product's subject is specification: bundle identifiers, provisioning profiles, signing certificates, pipeline definitions. The console's own vernacular is machine text — reverse-DNS identifiers (`ai.appfront.butterbird`), build hashes (`#adbe8a36`), scopes (`credentials:write`), ISO timestamps. Roughly seventy percent of the values on these screens are machine-generated, where glyph disambiguation and figure alignment matter more than warmth.

Three consequences:

1. **Cyanotype, not indigo.** `#14508C` is deeper and greener, with no violet in it.
2. **Condensed uppercase carries structure.** Page titles, table heads, and structural labels only. This is the voice of a spec sheet, and it is where the direction takes its risk.
3. **Monospace is a UI face, not a code face.** Every machine-generated value is set in mono; sans is for prose and human-entered names.

### The signature: expiry runway

The product is about things that expire — provisioning profiles, certificates, tokens, keys. A horizontal lifetime bar, on a **single shared time scale across the whole application**, appears in every row that has a lifetime.

The denominator is a fixed 365-day window with clamping. A one-year profile fills 62% of the bar; a 25-year keystore fills it completely with a `+` marker; an expired credential fills it in rust. The bar answers one question — "is this my problem this year?" — and the adjacent label states the exact value. A denominator taken from the maximum in the visible set would make bars incomparable between pages and break the scale the moment it was learned.

`no expiry` renders as a dashed hairline rather than an empty bar. An empty bar reads as "expired"; a dashed line reads as "there is no scale here." This treatment already exists in the vault and is being promoted into the system.

## Tokens

### Architecture

Raw ramps live in Tailwind's `@theme`. A semantic layer sits on CSS custom properties in `:root`, overridden under `.dark`. **Markup addresses semantics only** (`bg-surface-raised`, `text-muted`, `border-subtle`) and never raw palette classes such as `slate-200`. Without this rule the dark theme is unsolvable and the drift returns.

### Semantic tokens

| Token | Light | Dark |
|---|---|---|
| `surface` | `#F7F6F3` | `#0B1520` |
| `surface-raised` | `#FFFFFF` | `#101E2B` |
| `surface-sunken` | `#F0EEE9` | `#0A121B` |
| `border` | `#D8D5CD` | `#1E3247` |
| `border-subtle` | `#EDEBE5` | `#182838` |
| `border-control` | `#948D7E` | `#63748A` |
| `text` | `#131A22` | `#DCE4EC` |
| `text-muted` | `#4A5464` | `#93A4B6` |
| `text-faint` | `#5D6878` | `#7A8EA5` |
| `brand` | `#14508C` | `#6FA8DC` |
| `brand-fg` | `#FFFFFF` | `#08131C` |
| `brand-soft` | `#E8F0F8` | `#132B41` |
| `brand-soft-line` | `#C9DCEC` | `#1F425E` |
| `ok` | `#1F6F5C` | `#4FB39B` |
| `warn-text` | `#8A6220` | `#D9A54A` |
| `warn-fill` | `#A87524` | `#D9A54A` |
| `danger` | `#A8452B` | `#E0785F` |
| `nav` | `#131A22` | `#070E16` |
| `nav-text` | `#B8C2CE` | `#8A9CB0` |
| `nav-text-strong` | `#FFFFFF` | `#FFFFFF` |
| `nav-border` | `#262E38` | `#16202B` |

Contrast was computed, not estimated. Two values from the approved mockup failed WCAG AA for text and are corrected above:

- `brass #B07D2B` on white gives **3.63:1**. As the text of the "+11d" label that fails the 4.5:1 threshold, so `warn-text` is `#8A6220` (5.49:1). The bar fill stays `#B07D2B`, which only needs 3:1.
- `faint #6B7688` on sunken `#F0EEE9` gives **3.99:1**, and this is the colour of table-head labels, which are text. `text-faint` is `#5D6878` (4.89:1).
- `text-faint #6E8298` on dark `surface-raised #101E2B` gives **4.28:1**. Dark `text-faint` is `#7A8EA5` (5.03:1).

Verified passing, light: brand on white 8.14:1, `danger` 5.93:1, `ok` 6.02:1, `text-muted` on surface 7.02:1. Dark: brand 7.31:1, `warn` 7.59:1, `danger` 5.62:1, `ok` 6.65:1, `text-faint` 4.80:1.

Field and control borders delimit a UI component and need 3:1 under WCAG 1.4.11, which the decorative `border` token does not meet against white (1.47:1) and is not meant to. `border-control` carries that job: `#948D7E` light (3.26:1) and `#63748A` dark (3.55:1). Decorative table dividers have no threshold and are deliberately absent from the checked pair list. The sidebar is dark in both themes, so its emphasis text and its hairlines are deliberate constants rather than theme-varying colours: `nav-text-strong` and `nav-border` are still tokens, not raw palette classes, just with light and dark values that happen to match.

### Type scale — seven steps, no exceptions

| Step | Size / line-height | Use |
|---|---|---|
| `2xs` | 10 / 14 | Structural labels and table heads. The only place condensed uppercase appears besides page titles. |
| `xs` | 11.5 / 16 | Monospace values, metadata |
| `sm` | 13 / 19 | Default body inside tables |
| `base` | 14 / 21 | Prose |
| `lg` | 16 / 22 | Section headings |
| `xl` | 20 / 25 | Modal and detail-page titles |
| `3xl` | 30 / 32 | Page titles, condensed uppercase |

Arbitrary `text-[Npx]` values are prohibited. The 58 occurrences of `text-[9px]` collapse into `2xs`.

### Radius — three steps

`sm` 2px for buttons, badges, fields, and the table shell. `md` 3px for cards and modals. `full` for avatars and status dots only. Most of the 95 current `rounded-full` uses are badges and become near-rectangular; this is a visible change the direction requires.

### Fonts

`@fontsource/ibm-plex-sans`, `@fontsource/ibm-plex-sans-condensed`, `@fontsource/ibm-plex-mono` — latin and latin-ext subsets. Weights: Sans 400/500/600, Condensed 600, Mono 400/500. Imported in `app.css`. Implementation should prefer the variable packages where fontsource publishes them and fall back to static weights otherwise.

## Primitives

New directory `resources/js/Components/ui/`. Each component answers a specific finding in the current code; none is speculative.

**Page frame**

- `PageHeader` — eyebrow, title, description, plus `breadcrumbs` and `actions` slots. Closes the two-skeleton split.
- `WorkspacePage` — rewritten. Two named widths instead of three: `1240px` default and `720px` narrow for `Profile/Edit` and `Billing/Index`, which are genuinely single-column forms.
- `AppShell` — sidebar moved onto semantic tokens; hosts `ThemeToggle`.

**Data display**

- `Card` + `CardHeader` — title, count, meta ("262 in Demo Team"), and a right slot for the seal ("Encrypted at rest", "Recorded automatically"). This bar repeats verbatim on five pages.
- `DataTable` — column config, condensed uppercase head, optional sticky head, responsive stacking, divider rules, an `empty` slot, row links, and an expandable-row slot. Replaces four hand-rolled heads at three type sizes.
- `Runway` — the signature element. Props: `expiresAt` and optional `issuedAt`. Tone is derived internally from the remaining days, never passed in — a caller-supplied tone would let the colour disagree with the bar. `expiresAt: null` selects the dashed `no expiry` variant. Includes a text alternative for screen readers.
- `Badge` — tone × variant. Replaces roughly thirty inline pill spans.
- `StatTile` — the metric tiles in `Apps/Overview` (3) and `Apps/Builds/Show` (4).
- `EmptyState` — icon, title, body, action.
- `KeyValue` — definition rows for the "Application details", "Run details", and identifier-detail panels, currently hand-rolled `dt`/`dd` pairs with `text-[9px]` labels.

**Input and action**

- `Button` — variants `primary | secondary | ghost | danger | danger-quiet`, sizes `sm | md`, `loading`, optional icon. **Disabled renders sunken and muted, never in a brand tint.** This fixes the lavender pseudo-CTA.
- `Field` wrapping `Input`, `Select`, `Textarea`, `Checkbox`, `Radio`, `FileDrop` — label, hint, error.
- `FilterBar` — search, selects, and chips in one row. Unifies `Credentials` (search plus three selects), `AuditLog` (three selects, no search), and `Dashboard` (search inside the card header).
- `Modal` — header, body, footer, focus trap, Escape, scroll lock, `aria-modal`. Includes a `confirm` preset that replaces `window.confirm` in `ApiKeys/Index`. Ten modals currently duplicate the backdrop and none traps focus.
- `ThemeToggle` plus a theme store.
- `TabSelector` — kept and retuned to tokens, moving from `Components/` to `Components/ui/` with the others. The three colour maps over six tones collapse to "default" and "attention"; the removed tones are barely used.

### Fate of every class in `app.css`

| Current | Fate |
|---|---|
| `button`, `input`, `select`, `textarea` (`@layer base`) | Deleted — replaced by `Button` and `Field` |
| `body` (`@layer base`) | Kept, moved onto `surface` and `text` tokens |
| `.card` | Deleted — replaced by `Card` |
| `.secondary`, `.ghost`, `.danger`, `.icon-btn` | Deleted — become `Button` variants |
| `.label`, `.error` | Deleted — become `Field` internals |
| `.chip` | Deleted — becomes `TabSelector` and `Badge` internals |
| `.modal`, `.modal-backdrop` | Deleted — become `Modal` internals |
| `.vault-grid`, `.vault-head` | Deleted — page-specific classes, replaced by `DataTable` |
| `.eyebrow` | Deleted — becomes a `PageHeader` internal |
| `.muted` | Deleted — replaced by the `text-muted` token |
| `.row-tools` | Kept as a utility. It expresses hover-reveal behaviour rather than appearance, and `DataTable` uses it for row actions. |

What remains in `app.css` after the work: the Tailwind import, `@source` lines, `@theme` ramps, the semantic token layers for light and dark, the font imports, the `body` base rule, `.row-tools`, and the reduced-motion block.

**Ordering constraint:** until a page is migrated to `Button`, its buttons render unstyled. Primitives and the pilot page land first; the `@layer base` element selectors and the `@layer components` classes are removed only as their last consumer is migrated, which completes at the end of sub-project 2. Until then both systems coexist and the new primitives override the old base rules explicitly.

### Svelte mode

All new primitives use runes (`$props()`, `$state()`, `$derived`). Pages are converted to runes as they are migrated: 34 of 40 files are currently legacy mode (`export let`, 195 `on:click`), and legacy-mode pages never re-render `useForm.errors` or `processing`. `Auth/Login` and `Installer/*` were already converted for this reason. The redesign rewrites the markup anyway, which makes it the right moment — but this is a behaviour change, not only a visual one, and each migrated page needs its forms exercised.

## Pilot page: Credentials Vault

`Pages/Credentials/Index.svelte` (583 lines) and `Pages/Credentials/VaultCredentialFormModal.svelte` (306 lines).

Chosen because it exercises nearly the whole system: toned filter chips, search with three selects, a card header with a seal, a sticky table head, the runway, provenance badges, an expandable detail row, empty states, and a modal with file upload. Only `StatTile` and `KeyValue` go unused. If the system survives this page it survives the rest.

| Current | Becomes |
|---|---|
| `WorkspacePage` with no description (line 284) | `PageHeader` with a description (copy below) |
| `TabSelector` with rose/amber tones | `FilterBar` plus `TabSelector` with the "attention" tone |
| Loose search, two selects, and a sort select | `FilterBar` |
| `.card overflow-hidden p-0` with inner header and "Encrypted at rest" | `Card` + `CardHeader` |
| `.vault-head .vault-grid sticky` (line 351) | `DataTable` columns |
| Hand-rolled lifetime bar | `Runway` |
| "Imported from Expo" / "Secret synced from Expo" | `Badge` |
| `VaultCredentialFormModal` | `Modal` plus `Field`/`FileDrop` |

The page has no description today, so it needs copy. Eyebrow stays "Workspace security"; title stays "Credentials Vault"; description:

> Signing keys, provisioning profiles, and service accounts for this workspace's apps.

It deliberately does not say "encrypted at rest" — that claim already appears as the seal in the card header, and no element should quietly do double duty.

## Definition of done

1. Every semantic token pair meets WCAG AA in both themes: 4.5:1 for text, 3:1 for borders and the runway bar. The two corrections above are applied.
2. Visible keyboard focus on every interactive element — a cyanotype ring, 2px with 2px offset, on `:focus-visible`. This is new: the base `button` currently has no focus style at all.
3. `@media (prefers-reduced-motion: reduce)` neutralises transitions.
4. No theme flash: an inline script in the `<head>` of `app.blade.php` reads `localStorage` before first paint, defaulting to `prefers-color-scheme`.
5. Playwright screenshots of the pilot page in both themes at 1440px and 390px.
6. The 105 existing PHP tests stay green. Controllers and Inertia props are not touched.
7. An npm script `design:lint` greps `resources/js` for arbitrary `text-[Npx]`, raw palette classes (`slate-`, `indigo-`, `violet-`, `rose-`, `emerald-`, `amber-`), and `rounded-md|2xl|3xl`, failing with `file:line`.

On item 7: the repository has no test CI. `.github/workflows` contains only `demo-android.yml` and `demo-ios.yml`, which are demo build pipelines. The script is therefore local-only for now; adding a workflow is out of scope for this piece.

## Out of scope

This spec covers the foundation and one pilot page. The remaining work is sequenced as separate specs, each with its own plan:

2. **List and table pages** — `Dashboard`, `AuditLog/Index`, `ApiKeys/Index`, `Team/Index`, `Billing/Index`. `Integrations/Index` at 1,284 lines will likely need its own spec. The `app.css` element-selector removal completes here.
3. **App detail pages** — `Apps/AppLayout`, `Overview`, `Builds/Index`, `Builds/Show`, `Identifiers/Index`, `Identifiers/Show`, `Media/Index`, `Expo/Index`.
4. **Forms, modals, auth, installer** — the ten modals, `Profile/Edit`, `Installer/Index`, and the `Auth` pages, which currently use `violet-400` and their own typography.

Also out of scope: adding a JavaScript test runner (there is none today, and introducing one for this work would widen the scope), and any change to controllers, Inertia props, or routes.
