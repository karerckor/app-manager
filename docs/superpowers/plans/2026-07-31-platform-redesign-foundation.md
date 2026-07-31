# Platform Redesign Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the Blueprint design system's foundation into `apps/platform` — semantic tokens for light and dark, self-hosted IBM Plex, a flash-free theme toggle, and the rewritten app shell — so that every existing page renders on the new system without any page being rebuilt yet.

**Architecture:** Tokens live in CSS as the single source of truth (`resources/css/tokens.css`), consumed by Tailwind 4 through `@theme inline` so utilities like `bg-surface-raised` and `text-2xs` exist. Two Node scripts guard the system: a contrast checker that parses `tokens.css` and asserts WCAG AA on a declared pair list, and a design linter that rejects arbitrary pixel type sizes and raw palette classes. Both are tested with Node's built-in test runner, so no JavaScript test framework is added. Svelte components have no unit-test path in this repo, so they are verified by a successful `vite build` plus Playwright screenshots and computed-style assertions.

**Tech Stack:** Laravel 12 + Inertia 3 + Svelte 5 (runes) + Tailwind CSS 4.3.3 + Vite 8, Node 22.22.3 (`node --test`), `@fontsource/*` 5.3.0, Playwright via MCP.

## Global Constraints

- Markup addresses semantic tokens only (`bg-surface-raised`, `text-muted`, `border-subtle`). Raw palette classes (`slate-*`, `indigo-*`, `violet-*`, `rose-*`, `emerald-*`, `amber-*`) are prohibited in any file this plan creates or rewrites.
- Type scale is exactly seven steps: `2xs` 10/14, `xs` 11.5/16, `sm` 13/19, `base` 14/21, `lg` 16/22, `xl` 20/25, `3xl` 30/32. Arbitrary `text-[Npx]` is prohibited.
- Radius is exactly three steps: `sm` 2px (buttons, badges, fields, table shell), `md` 3px (cards, modals), `full` (avatars and status dots only).
- Condensed uppercase appears in exactly two places: page titles (`3xl`) and structural labels/table heads (`2xs`). Nowhere else.
- Every machine-generated value (identifiers, hashes, scopes, file names, timestamps) is set in `font-mono`. Sans is for prose and human-entered names.
- Fonts are self-hosted through npm. No CDN, no external font request — the product is self-hostable and would break in a closed network.
- Disabled buttons render sunken and muted, never in a brand tint.
- Every interactive element has a visible `:focus-visible` ring: 2px in `brand`, 2px offset.
- `@media (prefers-reduced-motion: reduce)` neutralises transitions.
- The 105 existing PHP tests must stay green. Controllers, Inertia props, and routes are not touched.
- Commit after every task. Branch is `design/platform-redesign-foundation`.

### Corrections to the spec's token table

Three values in `docs/superpowers/specs/2026-07-31-platform-redesign-foundation-design.md` fail contrast or are missing, found while computing the pair list for Task 1. **This plan is authoritative; Task 1 Step 6 updates the spec.**

| Token | Spec | Plan | Why |
|---|---|---|---|
| `text-faint` (dark) | `#6E8298` | `#7A8EA5` | `#6E8298` on `surface-raised` `#101E2B` is 4.28:1, below the 4.5:1 text threshold. `#7A8EA5` gives 5.03:1. |
| `warn-fill` (light) | `#B07D2B` | `#A87524` | As a runway bar against the `border-subtle` track, `#B07D2B` gives 3.05:1 — passing only by rounding. `#A87524` gives 3.38:1. |
| `border-control` | absent | `#948D7E` light / `#63748A` dark | Field and control borders delimit a UI component and need 3:1 (WCAG 1.4.11). The decorative `border` token is 1.47:1 against white, which is correct for a table divider but not for an input. |

---

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `resources/css/tokens.css` | Single source of truth for semantic tokens. Light values in `:root`, dark overrides in `.dark`. Nothing else. |
| `resources/js/design/theme.js` | Theme store: current value, `setTheme`, persistence to `localStorage`, `prefers-color-scheme` fallback. |
| `resources/js/Components/ui/ThemeToggle.svelte` | The control that drives the theme store. |
| `scripts/design/contrast.mjs` | Pure functions: parse tokens from CSS text, relative luminance, contrast ratio, check a pair list. No I/O. |
| `scripts/design/design-lint.mjs` | Pure function `findViolations(source, filename)` plus a CLI entry point. |
| `tests/design/contrast.test.js` | Asserts every declared token pair meets its minimum, in both themes. |
| `tests/design/design-lint.test.js` | Asserts the linter catches each prohibited pattern and passes clean input. |
| `resources/js/Components/ui/PageHeader.svelte` | Eyebrow, title, description, `breadcrumbs` and `actions` slots. |

**Modified**

| File | Change |
|---|---|
| `resources/css/app.css` | Import fonts and `tokens.css`; add `@theme` (families, type scale, radius) and `@theme inline` (semantic mapping); add the reduced-motion block. Existing `@layer base` and `@layer components` rules stay untouched — they are removed in sub-project 2 when their last consumer is migrated. |
| `resources/views/app.blade.php` | Inline no-flash theme script in `<head>`; `class="antialiased"` moves onto tokens. |
| `resources/js/app.js` | Inertia progress colour from violet `#a78bfa` to `brand`. |
| `resources/js/Components/AppShell.svelte` | Rewritten on tokens; runes; mobile navigation added; hosts `ThemeToggle`; focus rings. |
| `resources/js/Components/WorkspacePage.svelte` | Rewritten on tokens and runes; delegates the header to `PageHeader`; two named widths. |
| `package.json` | Font dependencies; `design:lint` and `test:design` scripts. |

**Deliberately not touched in this plan:** the ten page files, the ten modals, and the `@layer base`/`@layer components` rules in `app.css`. Pages inherit the new tokens through the shell and keep working; rebuilding them is sub-project 2.

---

## Task 1: Token layer, guarded by a contrast test

**Files:**
- Create: `scripts/design/contrast.mjs`
- Create: `tests/design/contrast.test.js`
- Create: `resources/css/tokens.css`
- Modify: `package.json` (add `test:design` script)
- Modify: `docs/superpowers/specs/2026-07-31-platform-redesign-foundation-design.md` (apply the three corrections)

**Interfaces:**
- Consumes: nothing.
- Produces: `resources/css/tokens.css` defining these custom properties in `:root` and overriding them in `.dark` — `--surface`, `--surface-raised`, `--surface-sunken`, `--border`, `--border-subtle`, `--border-control`, `--text`, `--text-muted`, `--text-faint`, `--brand`, `--brand-fg`, `--brand-soft`, `--brand-soft-line`, `--ok`, `--warn-text`, `--warn-fill`, `--danger`, `--nav`, `--nav-text`, `--nav-text-strong`, `--nav-border`.

The sidebar is dark in both themes, so its emphasis text and its hairlines are
deliberate constants rather than theme-varying colours. They still get tokens
(`--nav-text-strong`, `--nav-border`) instead of literal `text-white` and
`border-white/10`, because the global constraint prohibiting raw palette
classes has to be literally satisfiable — otherwise every reviewer relitigates
the same exception for the whole plan.
- Produces: `scripts/design/contrast.mjs` exporting `parseTokens(cssText) -> { light: Record<string,string>, dark: Record<string,string> }`, `luminance(hex) -> number`, `contrast(hexA, hexB) -> number`, and `PAIRS -> Array<{ fg: string, bg: string, min: number, theme: 'light'|'dark'|'both' }>`.

- [ ] **Step 1: Write the failing test**

Create `tests/design/contrast.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseTokens, contrast, luminance, PAIRS } from '../../scripts/design/contrast.mjs';

const css = readFileSync(new URL('../../resources/css/tokens.css', import.meta.url), 'utf8');
const tokens = parseTokens(css);

test('luminance matches known reference values', () => {
    assert.equal(luminance('#FFFFFF').toFixed(4), '1.0000');
    assert.equal(luminance('#000000').toFixed(4), '0.0000');
});

test('contrast is symmetric and matches a known pair', () => {
    assert.equal(contrast('#FFFFFF', '#000000').toFixed(2), '21.00');
    assert.equal(contrast('#000000', '#FFFFFF').toFixed(2), '21.00');
});

test('both themes define exactly the same token names', () => {
    assert.deepEqual(Object.keys(tokens.light).sort(), Object.keys(tokens.dark).sort());
});

test('every token is a six-digit hex value', () => {
    for (const theme of ['light', 'dark']) {
        for (const [name, value] of Object.entries(tokens[theme])) {
            assert.match(value, /^#[0-9A-Fa-f]{6}$/, `${theme}.${name} is "${value}"`);
        }
    }
});

test('every declared pair meets its contrast minimum', () => {
    const failures = [];
    for (const pair of PAIRS) {
        const themes = pair.theme === 'both' ? ['light', 'dark'] : [pair.theme];
        for (const theme of themes) {
            const fg = tokens[theme][pair.fg];
            const bg = tokens[theme][pair.bg];
            assert.ok(fg, `${theme} is missing token --${pair.fg}`);
            assert.ok(bg, `${theme} is missing token --${pair.bg}`);
            const ratio = contrast(fg, bg);
            if (ratio < pair.min) {
                failures.push(`${theme}: ${pair.fg} (${fg}) on ${pair.bg} (${bg}) = ${ratio.toFixed(2)}:1, needs ${pair.min}:1`);
            }
        }
    }
    assert.deepEqual(failures, []);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && node --test tests/design/`

Expected: FAIL — `Cannot find module '.../scripts/design/contrast.mjs'`.

- [ ] **Step 3: Write the contrast module**

Create `scripts/design/contrast.mjs`:

```js
/** Parse `--name: #hex;` declarations out of :root and .dark blocks. */
export function parseTokens(cssText) {
    const block = (selector) => {
        const start = cssText.indexOf(selector);
        if (start === -1) throw new Error(`tokens.css has no ${selector} block`);
        const open = cssText.indexOf('{', start);
        const close = cssText.indexOf('}', open);
        return cssText.slice(open + 1, close);
    };
    const read = (body) => {
        const out = {};
        for (const match of body.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/gi)) {
            out[match[1]] = match[2].trim();
        }
        return out;
    };
    return { light: read(block(':root')), dark: read(block('.dark')) };
}

const channel = (eight) => {
    const s = eight / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
};

/** WCAG 2.1 relative luminance. */
export function luminance(hex) {
    const n = parseInt(hex.slice(1), 16);
    const r = channel((n >> 16) & 255);
    const g = channel((n >> 8) & 255);
    const b = channel(n & 255);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio, always >= 1. */
export function contrast(hexA, hexB) {
    const a = luminance(hexA);
    const b = luminance(hexB);
    const [hi, lo] = a > b ? [a, b] : [b, a];
    return (hi + 0.05) / (lo + 0.05);
}

/**
 * Every pair the design system relies on.
 * min 4.5 = text (WCAG 1.4.3). min 3 = UI components and meaningful graphics (1.4.11).
 * Decorative dividers (--border on a surface) are intentionally absent: they carry
 * no information and no threshold applies.
 */
export const PAIRS = [
    // body and prose
    { fg: 'text', bg: 'surface', min: 4.5, theme: 'both' },
    { fg: 'text', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'text-muted', bg: 'surface', min: 4.5, theme: 'both' },
    { fg: 'text-muted', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // structural labels and table heads sit on the sunken surface
    { fg: 'text-faint', bg: 'surface-sunken', min: 4.5, theme: 'both' },
    { fg: 'text-faint', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // brand as text, as a fill, and inside its own soft container
    { fg: 'brand', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'brand', bg: 'brand-soft', min: 4.5, theme: 'both' },
    { fg: 'brand-fg', bg: 'brand', min: 4.5, theme: 'both' },
    // signal colours as text
    { fg: 'ok', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'warn-text', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'danger', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // sidebar
    { fg: 'nav-text', bg: 'nav', min: 4.5, theme: 'both' },
    { fg: 'nav-text-strong', bg: 'nav', min: 4.5, theme: 'both' },
    // runway bar fills against the track: meaningful graphics
    { fg: 'brand', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'warn-fill', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'danger', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'ok', bg: 'border-subtle', min: 3, theme: 'both' },
    // control borders delimit a UI component
    { fg: 'border-control', bg: 'surface-raised', min: 3, theme: 'both' },
    // the focus ring must be visible against what surrounds it
    { fg: 'brand', bg: 'surface', min: 3, theme: 'both' },
];
```

- [ ] **Step 4: Write the token layer**

Create `resources/css/tokens.css`:

```css
/*
 * Blueprint semantic tokens. Single source of truth.
 *
 * Markup must never reference a raw palette value. Every colour decision in the
 * application resolves through one of these names, which is what makes the dark
 * theme a single override block instead of a per-page rewrite.
 *
 * Guarded by tests/design/contrast.test.js — every pair the system relies on is
 * asserted against WCAG AA. Change a value here and run `npm run test:design`.
 */

:root {
    --surface: #F7F6F3;
    --surface-raised: #FFFFFF;
    --surface-sunken: #F0EEE9;
    --border: #D8D5CD;
    --border-subtle: #EDEBE5;
    --border-control: #948D7E;
    --text: #131A22;
    --text-muted: #4A5464;
    --text-faint: #5D6878;
    --brand: #14508C;
    --brand-fg: #FFFFFF;
    --brand-soft: #E8F0F8;
    --brand-soft-line: #C9DCEC;
    --ok: #1F6F5C;
    --warn-text: #8A6220;
    --warn-fill: #A87524;
    --danger: #A8452B;
    --nav: #131A22;
    --nav-text: #B8C2CE;
    --nav-text-strong: #FFFFFF;
    --nav-border: #262E38;
}

.dark {
    --surface: #0B1520;
    --surface-raised: #101E2B;
    --surface-sunken: #0A121B;
    --border: #1E3247;
    --border-subtle: #182838;
    --border-control: #63748A;
    --text: #DCE4EC;
    --text-muted: #93A4B6;
    --text-faint: #7A8EA5;
    --brand: #6FA8DC;
    --brand-fg: #08131C;
    --brand-soft: #132B41;
    --brand-soft-line: #1F425E;
    --ok: #4FB39B;
    --warn-text: #D9A54A;
    --warn-fill: #D9A54A;
    --danger: #E0785F;
    --nav: #070E16;
    --nav-text: #8A9CB0;
    --nav-text-strong: #FFFFFF;
    --nav-border: #16202B;
}
```

Add to `package.json` scripts:

```json
"test:design": "node --test tests/design/*.test.js"
```

The glob rather than the bare directory is deliberate. In this environment
(Node 22.22.3) `node --test tests/design/` resolves the directory as a CJS
module and throws `Cannot find module`, instead of discovering the test files
inside it. Verified directly. The glob is non-recursive, which is sufficient:
`tests/design/` holds exactly two flat files — this task's `contrast.test.js`
and Task 4's `design-lint.test.js` — and no subdirectory is planned.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd apps/platform && npm run test:design`

Expected: PASS, 5 tests. If the pair test fails it prints every failing pair with its actual ratio — fix the token, not the threshold.

- [ ] **Step 6: Apply the three corrections to the spec**

In `docs/superpowers/specs/2026-07-31-platform-redesign-foundation-design.md`, in the semantic token table: change dark `text-faint` from `#6E8298` to `#7A8EA5`; change light `warn-fill` from `#B07D2B` to `#A87524`; add a `border-control` row with `#948D7E` light and `#63748A` dark. Then, in the paragraph beginning "Contrast was computed, not estimated", add a third bullet:

```markdown
- `text-faint #6E8298` on dark `surface-raised #101E2B` gives **4.28:1**. Dark `text-faint` is `#7A8EA5` (5.03:1).
```

And append to that section:

```markdown
Field and control borders delimit a UI component and need 3:1 under WCAG 1.4.11, which the decorative `border` token does not meet against white (1.47:1) and is not meant to. `border-control` carries that job: `#948D7E` light (3.26:1) and `#63748A` dark (3.55:1). Decorative table dividers have no threshold and are deliberately absent from the checked pair list.
```

- [ ] **Step 7: Commit**

```bash
git add apps/platform/scripts/design/contrast.mjs apps/platform/tests/design/contrast.test.js apps/platform/resources/css/tokens.css apps/platform/package.json docs/superpowers/specs/2026-07-31-platform-redesign-foundation-design.md
git commit -m "feat(design): add semantic tokens guarded by a contrast test

Light and dark values in one override block, with every pair the system
relies on asserted against WCAG AA. Corrects three values from the spec:
dark text-faint failed at 4.28:1, the light runway fill passed only by
rounding, and control borders had no token meeting 1.4.11 at all."
```

---

## Task 2: Wire tokens, type scale, and radius into Tailwind

**Files:**
- Modify: `resources/css/app.css:1-10` (the `@import` and `@theme` region)

**Interfaces:**
- Consumes: the custom properties from Task 1.
- Produces: Tailwind utilities `bg-surface`, `bg-surface-raised`, `bg-surface-sunken`, `border-border`, `border-border-subtle`, `border-border-control`, `text-text`, `text-muted`, `text-faint`, `text-brand`, `bg-brand`, `text-brand-fg`, `bg-brand-soft`, `border-brand-soft-line`, `text-ok`, `bg-ok`, `text-warn-text`, `bg-warn-fill`, `text-danger`, `bg-danger`, `bg-nav`, `text-nav-text`; type steps `text-2xs`, `text-xs`, `text-sm`, `text-base`, `text-lg`, `text-xl`, `text-3xl`; radii `rounded-sm`, `rounded-md`, `rounded-full`; families `font-sans`, `font-condensed`, `font-mono`.
- Produces: the `dark` variant driven by a `.dark` class on `<html>`, not by the media query.

- [ ] **Step 1: Replace the import and theme region of `app.css`**

Replace lines 1–10 of `resources/css/app.css` (the `@import 'tailwindcss';`, the three `@source` lines, and the existing `@theme` block) with:

```css
@import 'tailwindcss';
@import './tokens.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../js/**/*.svelte';

/* Class-driven dark mode: the no-flash script in app.blade.php puts .dark on <html>. */
@custom-variant dark (&:where(.dark, .dark *));

@theme {
    --font-sans: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';
    --font-condensed: 'IBM Plex Sans Condensed', 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;

    /* Seven steps. Arbitrary text-[Npx] is prohibited — design:lint enforces it. */
    --text-2xs: 0.625rem;        /* 10px — structural labels, table heads */
    --text-2xs--line-height: 0.875rem;
    --text-xs: 0.71875rem;       /* 11.5px — monospace values, metadata */
    --text-xs--line-height: 1rem;
    --text-sm: 0.8125rem;        /* 13px — default body inside tables */
    --text-sm--line-height: 1.1875rem;
    --text-base: 0.875rem;       /* 14px — prose */
    --text-base--line-height: 1.3125rem;
    --text-lg: 1rem;             /* 16px — section headings */
    --text-lg--line-height: 1.375rem;
    --text-xl: 1.25rem;          /* 20px — modal and detail titles */
    --text-xl--line-height: 1.5625rem;
    --text-3xl: 1.875rem;        /* 30px — page titles, condensed uppercase */
    --text-3xl--line-height: 2rem;

    /* Three steps. sm for controls, md for containers, full for avatars and dots. */
    --radius-sm: 2px;
    --radius-md: 3px;
}

/* `inline` so the generated utilities reference var(--token) and follow .dark at runtime. */
@theme inline {
    --color-surface: var(--surface);
    --color-surface-raised: var(--surface-raised);
    --color-surface-sunken: var(--surface-sunken);
    --color-border: var(--border);
    --color-border-subtle: var(--border-subtle);
    --color-border-control: var(--border-control);
    --color-text: var(--text);
    --color-muted: var(--text-muted);
    --color-faint: var(--text-faint);
    --color-brand: var(--brand);
    --color-brand-fg: var(--brand-fg);
    --color-brand-soft: var(--brand-soft);
    --color-brand-soft-line: var(--brand-soft-line);
    --color-ok: var(--ok);
    --color-warn-text: var(--warn-text);
    --color-warn-fill: var(--warn-fill);
    --color-danger: var(--danger);
    --color-nav: var(--nav);
    --color-nav-text: var(--nav-text);
    --color-nav-strong: var(--nav-text-strong);
    --color-nav-border: var(--nav-border);
}

@layer base {
    :focus-visible {
        outline: 2px solid var(--brand);
        outline-offset: 2px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
```

Leave the existing `@layer base` body/input/button rules and the whole `@layer components` block below this, unchanged. They are removed in sub-project 2 when their last consumer is migrated. Change only the `body` rule's colours:

```css
body { @apply min-h-screen bg-surface text-text antialiased; }
```

- [ ] **Step 2: Build and assert the utilities exist**

Run:

```bash
cd apps/platform && npx vite build 2>&1 | tail -5
```

Expected: build succeeds, no unknown-utility errors.

Then confirm the generated CSS actually contains the new utilities and the dark variant. Run:

```bash
cd apps/platform && for u in 'bg-surface-raised' 'text-faint' 'border-border-control' 'text-2xs' 'rounded-sm' 'font-condensed'; do printf '%-24s ' "$u"; grep -qF "$u" public/build/assets/*.css && echo present || echo MISSING; done
grep -c '\.dark' public/build/assets/*.css
```

Expected: all six `present`. The `.dark` count is non-zero.

If a utility is `MISSING`, it is because nothing in `resources/js` uses it yet and Tailwind tree-shakes it. That is correct behaviour, not a failure — to verify in isolation, temporarily add a `<div class="bg-surface-raised text-faint border-border-control text-2xs rounded-sm font-condensed"></div>` to `resources/js/Components/AppShell.svelte`, rebuild, re-run the greps, then remove it.

- [ ] **Step 3: Confirm the contrast test still passes**

Run: `cd apps/platform && npm run test:design`

Expected: PASS. `tokens.css` was not edited, but this catches an accidental change to it while restructuring `app.css`.

- [ ] **Step 4: Commit**

```bash
git add apps/platform/resources/css/app.css
git commit -m "feat(design): map semantic tokens onto Tailwind utilities

Seven type steps and three radii replace the ad-hoc scale. @theme inline
so utilities resolve var(--token) at runtime and follow .dark. Adds the
global focus-visible ring and the reduced-motion block. The old base and
component rules stay until their last consumer is migrated."
```

---

## Task 3: Self-hosted IBM Plex

**Files:**
- Modify: `package.json` (dependencies)
- Modify: `resources/css/app.css` (font imports at the very top)

**Interfaces:**
- Consumes: the `--font-sans`, `--font-condensed`, `--font-mono` declarations from Task 2.
- Produces: `IBM Plex Sans` at 400/500/600, `IBM Plex Sans Condensed` at 600, and `IBM Plex Mono` at 400/500, latin and latin-ext, served from the build output.

**Deviation from the spec, with reason:** the spec says to prefer fontsource's variable packages. `@fontsource-variable/ibm-plex-sans` exists but ships no subset-split entry points (only `index.css`, `standard.css`, `wght.css`, and their italics), and `@fontsource-variable/ibm-plex-mono` and `@fontsource-variable/ibm-plex-sans-condensed` do not exist at all. Mixing a variable Sans with static Condensed and Mono would also risk visibly different weight rendering across one superfamily. All three families therefore use static weights with explicit `latin-` and `latin-ext-` files, which is what makes the spec's subset requirement enforceable. Verified against the 5.3.0 registry tarballs.

- [ ] **Step 1: Install the three families**

Run:

```bash
cd apps/platform && npm install --save-exact @fontsource/ibm-plex-sans@5.3.0 @fontsource/ibm-plex-sans-condensed@5.3.0 @fontsource/ibm-plex-mono@5.3.0
```

- [ ] **Step 2: Verify the exact entry points exist before importing them**

Run:

```bash
cd apps/platform && ls node_modules/@fontsource/ibm-plex-sans/latin-{400,500,600}.css node_modules/@fontsource/ibm-plex-sans/latin-ext-{400,500,600}.css node_modules/@fontsource/ibm-plex-sans-condensed/latin-600.css node_modules/@fontsource/ibm-plex-sans-condensed/latin-ext-600.css node_modules/@fontsource/ibm-plex-mono/latin-{400,500}.css node_modules/@fontsource/ibm-plex-mono/latin-ext-{400,500}.css
```

Expected: all twelve paths listed, no `No such file or directory`. Twelve entry
points, one woff2 each, which is where Step 4's expected count of 12 comes from.

- [ ] **Step 3: Import them at the top of `app.css`**

Insert above the `@import 'tailwindcss';` line — font imports must precede Tailwind so the `@font-face` rules land in the base cascade:

```css
/* Self-hosted. No CDN: the product is self-hostable and an external font
   request would leave the interface unstyled in a closed network. */
@import '@fontsource/ibm-plex-sans/latin-400.css';
@import '@fontsource/ibm-plex-sans/latin-500.css';
@import '@fontsource/ibm-plex-sans/latin-600.css';
@import '@fontsource/ibm-plex-sans/latin-ext-400.css';
@import '@fontsource/ibm-plex-sans/latin-ext-500.css';
@import '@fontsource/ibm-plex-sans/latin-ext-600.css';
@import '@fontsource/ibm-plex-sans-condensed/latin-600.css';
@import '@fontsource/ibm-plex-sans-condensed/latin-ext-600.css';
@import '@fontsource/ibm-plex-mono/latin-400.css';
@import '@fontsource/ibm-plex-mono/latin-500.css';
@import '@fontsource/ibm-plex-mono/latin-ext-400.css';
@import '@fontsource/ibm-plex-mono/latin-ext-500.css';
```

- [ ] **Step 4: Build and confirm the woff2 files ship**

Run:

```bash
cd apps/platform && npx vite build 2>&1 | tail -3 && ls public/build/assets/ | grep -c 'ibm-plex.*\.woff2'
```

Expected: build succeeds; the count is 12.

- [ ] **Step 5: Confirm no external font request remains**

Run:

```bash
cd apps/platform && grep -rnE "fonts\.(googleapis|gstatic|bunny)|@fonts\b" resources/views resources/css || echo "no external font references"
```

Expected: one hit and one hit only — a literal `@fonts` on line 9 of
`resources/views/welcome.blade.php`. That is pre-existing, out of this task's
file scope, and not an external font request: `@fonts` is registered as a Blade
directive by neither the application nor the framework, so Blade emits it as
text, and `welcome.blade.php` is referenced by no route. Leave it alone.

Note the `\b` in `@fonts\b`: without it the pattern matches this task's own
`@fontsource/…` import lines twelve times over, since `@fonts` is a prefix of
`@fontsource`. The word boundary is what makes the check mean anything.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/package.json apps/platform/package-lock.json apps/platform/resources/css/app.css
git commit -m "feat(design): self-host IBM Plex Sans, Condensed, and Mono

No fonts were loaded at all before this: app.css declared Inter but
app.blade.php had no @fonts directive, no link, and no @font-face, so the
interface rendered in the OS UI font. Static weights with explicit latin
and latin-ext subsets — the variable packages ship no subset entry points
and two of the three families have no variable build."
```

---

## Task 4: The design linter

**Files:**
- Create: `scripts/design/design-lint.mjs`
- Create: `tests/design/design-lint.test.js`
- Modify: `package.json` (add `design:lint` script)

**Interfaces:**
- Consumes: nothing.
- Produces: `scripts/design/design-lint.mjs` exporting `findViolations(source, filename) -> Array<{ file: string, line: number, rule: string, match: string }>`, plus a CLI that walks the paths given as arguments and exits 1 on any violation.

**One gap left open deliberately.** Tailwind's bare `rounded` (its DEFAULT radius)
is off-scale but is not flagged: the `reject` callback returns false on an empty
suffix. Closing it would mean flagging the standalone token `rounded`, which also
matches the English word in a comment or string — and bare `rounded` appears zero
times in the current 40 source files, so the false-positive cost exceeds the
benefit. Revisit if it ever appears. Recorded here rather than left implicit, so
nobody reads its absence as an oversight.

**Scope note:** the existing pages contain hundreds of violations by design — they have not been migrated yet. The `design:lint` script therefore takes explicit paths, and `package.json` points it only at the directories this plan creates. Each later sub-project widens that path list as it migrates pages. A whole-tree run is the finish line, not the starting point.

- [ ] **Step 1: Write the failing test**

Create `tests/design/design-lint.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findViolations } from '../../scripts/design/design-lint.mjs';

test('flags arbitrary pixel type sizes', () => {
    const found = findViolations('<p class="text-[9px] font-bold">x</p>', 'a.svelte');
    assert.equal(found.length, 1);
    assert.equal(found[0].rule, 'arbitrary-type-size');
    assert.equal(found[0].match, 'text-[9px]');
    assert.equal(found[0].line, 1);
});

test('flags raw palette classes across every prefix and utility', () => {
    const source = [
        '<div class="bg-slate-200">',
        '<div class="text-indigo-600">',
        '<div class="border-violet-400">',
        '<div class="ring-rose-500">',
        '<div class="divide-emerald-100">',
        '<div class="from-amber-300">',
    ].join('\n');
    const found = findViolations(source, 'b.svelte');
    assert.equal(found.length, 6);
    assert.deepEqual(found.map((v) => v.line), [1, 2, 3, 4, 5, 6]);
    for (const violation of found) assert.equal(violation.rule, 'raw-palette');
});

test('flags radii outside the three-step scale', () => {
    const found = findViolations('<div class="rounded-2xl rounded-3xl rounded-lg rounded-xl">', 'c.svelte');
    assert.equal(found.length, 4);
    for (const violation of found) assert.equal(violation.rule, 'off-scale-radius');
});

test('allows the three permitted radii', () => {
    assert.deepEqual(findViolations('<div class="rounded-sm rounded-md rounded-full">', 'd.svelte'), []);
});

test('flags an off-scale step behind a directional infix', () => {
    const found = findViolations('<div class="rounded-t-lg rounded-b-sm">', 'g.svelte');
    assert.equal(found.length, 1);
    assert.equal(found[0].match, 'rounded-t-lg');
});

test('flags arbitrary bracket radii, once each', () => {
    const found = findViolations('<div class="rounded-[2px] rounded-t-[22%]">', 'h.svelte');
    assert.equal(found.length, 2);
    for (const violation of found) assert.equal(violation.rule, 'arbitrary-radius');
});

test('flags the ramps most easily left out of the list', () => {
    const source = '<div class="bg-pink-500">\n<div class="text-fuchsia-600">\n<div class="border-lime-400">';
    const found = findViolations(source, 'i.svelte');
    assert.equal(found.length, 3);
    for (const violation of found) assert.equal(violation.rule, 'raw-palette');
});

test('allows semantic tokens and named type steps', () => {
    const clean = '<p class="bg-surface-raised text-faint text-2xs font-condensed rounded-sm border-border-control">ok</p>';
    assert.deepEqual(findViolations(clean, 'e.svelte'), []);
});

test('ignores hex values and identifiers that merely look like classes', () => {
    const source = '<span>ai.appfront.butterbird</span>\n<!-- #14508C -->';
    assert.deepEqual(findViolations(source, 'f.svelte'), []);
});

test('reports the filename it was given', () => {
    const found = findViolations('<i class="bg-slate-50">', 'Components/ui/Badge.svelte');
    assert.equal(found[0].file, 'Components/ui/Badge.svelte');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/platform && node --test tests/design/design-lint.test.js`

Expected: FAIL — `Cannot find module '.../scripts/design/design-lint.mjs'`.

- [ ] **Step 3: Write the linter**

Create `scripts/design/design-lint.mjs`:

```js
#!/usr/bin/env node
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

// All 22 of Tailwind's default ramps. Omitting any of them leaves a silent hole:
// `fuchsia` and `pink` are in live use in Pages/Apps/Media/Index.svelte today.
const RAW_PALETTES = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
const UTILITY_PREFIXES = ['bg', 'text', 'border', 'ring', 'divide', 'from', 'via', 'to', 'outline', 'shadow', 'accent', 'fill', 'stroke', 'decoration', 'placeholder', 'caret'];
const RADIUS_STEPS = new Set(['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', 'full']);
const ALLOWED_RADII = new Set(['sm', 'md', 'full', 'none']);

const RULES = [
    {
        name: 'arbitrary-type-size',
        pattern: /\btext-\[[^\]]+\]/g,
        hint: 'use one of the seven named steps: text-2xs xs sm base lg xl 3xl',
    },
    {
        name: 'raw-palette',
        pattern: new RegExp(`\\b(?:${UTILITY_PREFIXES.join('|')})-(?:${RAW_PALETTES.join('|')})-\\d{2,3}\\b`, 'g'),
        hint: 'use a semantic token: surface, surface-raised, surface-sunken, border, border-subtle, border-control, text, muted, faint, brand, brand-fg, brand-soft, brand-soft-line, ok, warn-text, warn-fill, danger, nav, nav-text',
    },
    {
        // The radius counterpart of arbitrary-type-size. `rounded-[2px]` appears four
        // times in AppShell.svelte and `rounded-[22%]` three times in Media/Index.svelte,
        // so this is an active hole, not a hypothetical one. Note this pattern requires
        // a bracket, so it never double-counts with off-scale-radius below, whose own
        // pattern backs off to a bare `rounded` match on the same text.
        name: 'arbitrary-radius',
        pattern: /\brounded(?:-[a-z]+)?-\[[^\]]+\]/g,
        hint: 'radius is three steps: rounded-sm (controls), rounded-md (containers), rounded-full (avatars and dots)',
    },
    {
        name: 'off-scale-radius',
        pattern: /\brounded(?:-[a-z]+)?-?([a-z0-9]*)\b/g,
        hint: 'radius is three steps: rounded-sm (controls), rounded-md (containers), rounded-full (avatars and dots)',
        reject: (match) => {
            const suffix = match.replace(/^rounded-?/, '');
            if (suffix === '') return false;
            // The pattern also swallows a trailing directional infix (the "t" in
            // "rounded-t-lg") or trailing noise. The size step is always the last
            // dash-separated segment, and only a real Tailwind radius keyword that
            // is not one of the permitted three counts as a violation — otherwise
            // any hyphenated class beginning "rounded" becomes a false positive.
            const step = suffix.split('-').pop();
            return RADIUS_STEPS.has(step) && !ALLOWED_RADII.has(step);
        },
    },
];

/** Find every design-system violation in one file's source text. */
export function findViolations(source, filename) {
    const violations = [];
    source.split('\n').forEach((text, index) => {
        for (const rule of RULES) {
            for (const match of text.matchAll(rule.pattern)) {
                if (rule.reject && !rule.reject(match[0])) continue;
                violations.push({ file: filename, line: index + 1, rule: rule.name, match: match[0] });
            }
        }
    });
    return violations;
}

const walk = (path, out = []) => {
    for (const entry of readdirSync(path)) {
        const full = join(path, entry);
        if (statSync(full).isDirectory()) walk(full, out);
        else if (/\.(svelte|js)$/.test(full)) out.push(full);
    }
    return out;
};

if (import.meta.url === `file://${process.argv[1]}`) {
    const roots = process.argv.slice(2);
    if (roots.length === 0) {
        console.error('usage: design-lint.mjs <path> [path...]');
        process.exit(2);
    }
    const all = roots.flatMap((root) => (statSync(root).isDirectory() ? walk(root) : [root]));
    const violations = all.flatMap((file) => findViolations(readFileSync(file, 'utf8'), relative(process.cwd(), file)));

    if (violations.length === 0) {
        console.log(`design:lint — ${all.length} file(s) clean`);
        process.exit(0);
    }
    for (const violation of violations) {
        const hint = RULES.find((rule) => rule.name === violation.rule).hint;
        console.error(`${violation.file}:${violation.line}  ${violation.rule}  "${violation.match}"\n    ${hint}`);
    }
    console.error(`\n${violations.length} violation(s) in ${all.length} file(s)`);
    process.exit(1);
}
```

Add to `package.json` scripts:

```json
"design:lint": "node scripts/design/design-lint.mjs resources/js/Components/ui resources/js/design"
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/platform && node --test tests/design/design-lint.test.js`

Expected: PASS, 7 tests.

- [ ] **Step 5: Confirm the CLI works on a known-dirty file and a clean directory**

Run:

```bash
cd apps/platform && node scripts/design/design-lint.mjs resources/js/Pages/AuditLog/Index.svelte; echo "exit=$?"
```

Expected: many violations printed with `file:line`, `exit=1`. This is the un-migrated baseline and is correct.

Then run:

```bash
cd apps/platform && mkdir -p resources/js/Components/ui resources/js/design && npm run design:lint; echo "exit=$?"
```

Expected: `design:lint — 0 file(s) clean`, `exit=0`.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/scripts/design/design-lint.mjs apps/platform/tests/design/design-lint.test.js apps/platform/package.json
git commit -m "feat(design): add the drift guard

Rejects arbitrary pixel type sizes, raw palette classes, and off-scale
radii with file:line. Scoped to the directories that are already migrated
and widened by each later sub-project — a whole-tree run is the finish
line, not the starting point."
```

---

## Task 5: Theme store, no-flash boot, and the toggle

**Files:**
- Create: `resources/js/design/theme.js`
- Create: `resources/js/Components/ui/ThemeToggle.svelte`
- Modify: `resources/views/app.blade.php:3-13`
- Modify: `resources/js/app.js:7`

**Interfaces:**
- Consumes: the `.dark` variant from Task 2.
- Produces: `resources/js/design/theme.js` exporting `theme` (a read-only Svelte store of `'light' | 'dark'` — writable internally, exposed as `{ subscribe }`), `setTheme(value)`, `toggleTheme()`, and the constant `THEME_STORAGE_KEY = 'app-manager-theme'`. Every environment call that can throw is guarded: the store must keep working where `localStorage` or `matchMedia` is unavailable.
- Produces: `ThemeToggle.svelte`, taking no props.

- [ ] **Step 1: Write the theme store**

Create `resources/js/design/theme.js`:

```js
import { writable } from 'svelte/store';

// Must match the literal in resources/views/app.blade.php's boot script, which
// runs before any module loads and so cannot import this.
export const THEME_STORAGE_KEY = 'app-manager-theme';

/*
 * localStorage and matchMedia both throw in some privacy modes and embedded
 * webviews. The boot script guards them for the same reason; the store has to
 * as well, or a private-mode user gets a toggle that silently does nothing.
 */
const readStored = () => {
    try {
        return localStorage.getItem(THEME_STORAGE_KEY);
    } catch {
        return null;
    }
};

/** The value the inline boot script in app.blade.php already applied to <html>. */
const current = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');

/*
 * Writable internally, exported as { subscribe } only. A `readable` whose value
 * is pushed by a `set` captured in its start function goes stale whenever the
 * theme changes before anything has subscribed: the DOM updates, the store does
 * not, and the first subscriber then reads the module-load value. Holding the
 * value here instead means it tracks the DOM regardless of subscriber timing,
 * while callers still cannot write to it except through setTheme.
 */
const store = writable(current());

const apply = (value) => {
    document.documentElement.classList.toggle('dark', value === 'dark');
    document.documentElement.style.colorScheme = value;
    store.set(value);
};

export const theme = { subscribe: store.subscribe };

export const setTheme = (value) => {
    try {
        localStorage.setItem(THEME_STORAGE_KEY, value);
    } catch {
        // Storage unavailable. The theme still applies for this page's lifetime.
    }
    apply(value);
};

export const toggleTheme = () => setTheme(current() === 'dark' ? 'light' : 'dark');

/*
 * Follow the OS only while the user has expressed no preference of their own.
 * Read storage fresh on each event rather than capturing a flag, so the guard
 * cannot be defeated by ordering. One listener for the page's lifetime — this is
 * a singleton module in a single-page app, so there is nothing to tear down.
 */
try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
        if (readStored()) return;
        apply(event.matches ? 'dark' : 'light');
    });
} catch {
    // No matchMedia. Whatever the boot script decided stands.
}
```

- [ ] **Step 2: Add the no-flash boot script**

In `resources/views/app.blade.php`, insert immediately after the `<meta name="csrf-token">` line and before `@vite`. It must run before the stylesheet so the first paint is already correct:

```blade
        <script>
            // Runs before first paint: reading the stored theme here is what stops
            // the light palette flashing in front of a dark-theme user.
            // The key must match THEME_STORAGE_KEY in resources/js/design/theme.js.
            // It is duplicated because this runs before any module loads.
            (function () {
                try {
                    var stored = localStorage.getItem('app-manager-theme');
                    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (dark) document.documentElement.classList.add('dark');
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {
                    // Private mode can throw on localStorage. Light is the safe default.
                }
            })();
        </script>
```

- [ ] **Step 3: Write the toggle**

Create `resources/js/Components/ui/ThemeToggle.svelte`:

```svelte
<script>
    import { Moon, Sun } from '@lucide/svelte';
    import { theme, toggleTheme } from '../../design/theme.js';
</script>

<button
    type="button"
    onclick={toggleTheme}
    class="grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none transition hover:bg-nav-text/10 hover:text-nav-strong"
    aria-label={$theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
    title={$theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
>
    {#if $theme === 'dark'}
        <Sun class="h-4 w-4" strokeWidth={1.9} />
    {:else}
        <Moon class="h-4 w-4" strokeWidth={1.9} />
    {/if}
</button>
```

- [ ] **Step 4: Recolour the Inertia progress bar**

In `resources/js/app.js`, change line 7 from `progress: { color: '#a78bfa' },` to:

```js
    progress: { color: '#14508C' },
```

This is the one place a literal hex is unavoidable — Inertia's progress bar is created outside the CSS cascade and cannot read a custom property. It is the light `brand` value.

- [ ] **Step 5: Verify no flash and that the toggle persists**

Start the dev server (`npm run dev` in one shell, `php artisan serve` in another), then with Playwright:

1. Navigate to the applications page and confirm it renders.
2. Run `document.documentElement.classList.contains('dark')` — expect `false` on a fresh profile with a light OS preference.
3. Click the toggle, then re-read the class — expect `true`.
4. Read `localStorage.getItem('app-manager-theme')` — expect `"dark"`.
5. Reload the page and take a screenshot immediately. The page must be dark in the first frame, with no light flash.
6. Confirm `document.documentElement.style.colorScheme` is `dark`, so native form controls and scrollbars follow.

- [ ] **Step 6: Confirm the design linter is still clean**

Run: `cd apps/platform && npm run design:lint`

Expected: `2 file(s) clean` — `ThemeToggle.svelte` and `theme.js`.

- [ ] **Step 7: Commit**

```bash
git add apps/platform/resources/js/design/theme.js apps/platform/resources/js/Components/ui/ThemeToggle.svelte apps/platform/resources/views/app.blade.php apps/platform/resources/js/app.js
git commit -m "feat(design): add theme switching with a flash-free boot

The inline script in <head> applies the stored theme before first paint.
The store follows the OS only while the user has expressed no preference.
Inertia's progress bar moves off violet onto brand."
```

---

## Task 6: Rewrite the app shell

**Files:**
- Modify: `resources/js/Components/AppShell.svelte` (full rewrite, currently 110 lines)

**Interfaces:**
- Consumes: tokens from Task 2, `ThemeToggle` from Task 5.
- Produces: `AppShell` accepting the same four props as before — `auth`, `teams`, `currentTeam`, `active` — so all ten existing consumers keep working untouched. Converted to runes: `let { auth, teams = [], currentTeam = null, active = 'apps' } = $props()`.

**Added scope, flagged:** the current shell has **no mobile navigation at all**. The sidebar is `hidden … lg:flex` and the `lg:hidden` header holds only the logo, a profile link, and sign-out. Below 1024px there is no way to reach Applications, Credentials, or anything else. The definition of done requires the pilot page to work at 390px, and a page you cannot navigate away from does not pass, so this task adds a drawer. This is new behaviour rather than a restyle — call it out in review.

- [ ] **Step 1: Rewrite the shell**

Replace the entire contents of `resources/js/Components/AppShell.svelte`:

```svelte
<script>
    import { Link, router } from '@inertiajs/svelte';
    import { LayoutGrid, KeyRound, Blocks, Users, LogOut, ScrollText, CreditCard, ShieldCheck, BellRing, Menu, X } from '@lucide/svelte';
    import ThemeToggle from './ui/ThemeToggle.svelte';

    let { auth, teams = [], currentTeam = null, active = 'apps' } = $props();

    let drawerOpen = $state(false);

    const base = $derived(currentTeam?.slug ? `/accounts/${currentTeam.slug}` : null);
    const url = (suffix) => (base ? `${base}${suffix}` : '/dashboard');

    const items = $derived([
        { key: 'apps', label: 'Applications', href: url('/apps'), icon: LayoutGrid, show: true },
        { key: 'credentials', label: 'Credentials Vault', href: url('/credentials'), icon: ShieldCheck, show: true },
        { key: 'api-keys', label: 'API keys', href: url('/api-keys'), icon: KeyRound, show: currentTeam?.owner_id === auth.user.id },
        { key: 'audit', label: 'Audit log', href: url('/audit'), icon: ScrollText, show: ['owner', 'admin'].includes(auth?.team?.role) },
        { key: 'integrations', label: 'Integrations', href: url('/integrations'), icon: Blocks, show: Boolean(auth?.team?.permissions?.manage_integrations) },
        { key: 'team', label: 'Team access', href: url('/team'), icon: Users, show: true },
        { key: 'billing', label: 'Billing', href: url('/billing'), icon: CreditCard, show: Boolean(auth?.billing?.enabled) && currentTeam?.owner_id === auth?.user?.id },
    ].filter((item) => item.show));

    const tools = $derived([
        { key: 'push-notifications', label: 'Push notifications', href: url('/tools/push-notifications'), icon: BellRing },
    ]);

    const switchTeam = (event) => router.put(`/accounts/${event.currentTarget.value}/switch`);
    const logout = () => router.post('/logout');
    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

    const linkClass = (key) => [
        'flex items-center gap-3 rounded-sm px-3 py-2 text-sm transition',
        key === active
            ? 'bg-brand text-brand-fg font-semibold'
            : 'text-nav-text hover:bg-nav-text/10 hover:text-nav-strong',
    ].join(' ');
</script>

{#snippet nav(scope)}
    <Link href={url('/apps')} class="mb-6 flex items-center gap-2.5 px-2">
        <span class="grid h-8 w-8 grid-cols-2 gap-0.5 rounded-sm bg-brand p-1.5">
            <!-- Square tiles, no radius: at ~10px a 1px rounding is invisible, an
                 arbitrary bracket radius fails design:lint, and a technical-drawing
                 grid is the right read for this mark anyway. -->
            <i class="bg-brand-fg"></i><i class="bg-brand-fg/60"></i>
            <i class="bg-brand-fg/60"></i><i class="bg-brand-fg"></i>
        </span>
        <span class="min-w-0">
            <strong class="block font-condensed text-xs font-semibold tracking-[0.06em] text-nav-strong uppercase">App Manager</strong>
            <small class="block font-mono text-2xs text-nav-text">developer console</small>
        </span>
    </Link>

    <!-- The id is scoped: this snippet renders twice (sidebar and drawer), and a
         duplicate id makes the browser resolve BOTH labels to the first match in
         document order — the hidden desktop select — so the drawer's visible label
         would click through to a control that cannot take focus. -->
    <div class="mb-5 rounded-sm border border-nav-border p-2">
        <label for="team-{scope}" class="mb-1 block font-condensed text-2xs font-semibold tracking-[0.18em] text-nav-text uppercase">Workspace</label>
        <select
            id="team-{scope}"
            class="w-full border-0 bg-transparent p-0.5 text-sm font-medium text-nav-strong shadow-none focus:ring-0"
            value={currentTeam?.slug ?? ''}
            onchange={switchTeam}
        >
            {#each teams as team (team.slug)}<option class="text-text" value={team.slug}>{team.name}</option>{/each}
        </select>
    </div>

    <nav class="space-y-0.5" aria-label="Main">
        {#each items as item (item.key)}
            <Link href={item.href} class={linkClass(item.key)} aria-current={item.key === active ? 'page' : undefined}>
                <item.icon class="h-4 w-4 shrink-0" strokeWidth={1.8} />
                {item.label}
            </Link>
        {/each}

        <p class="px-3 pt-4 pb-1 font-condensed text-2xs font-semibold tracking-[0.18em] text-nav-text/70 uppercase">Tools</p>
        {#each tools as item (item.key)}
            <Link href={item.href} class={linkClass(item.key)} aria-current={item.key === active ? 'page' : undefined}>
                <item.icon class="h-4 w-4 shrink-0" strokeWidth={1.8} />
                {item.label}
            </Link>
        {/each}
    </nav>

    <div class="mt-auto flex items-center gap-2 border-t border-nav-border pt-3">
        <Link href="/profile" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand/20 font-mono text-2xs font-semibold text-nav-strong" aria-label="Open profile">
            {initials(auth.user.name)}
        </Link>
        <Link href="/profile" class="min-w-0 flex-1 rounded-sm">
            <p class="truncate text-xs font-medium text-nav-strong">{auth.user.name}</p>
            <p class="truncate font-mono text-2xs text-nav-text">{auth.user.email}</p>
        </Link>
        <ThemeToggle />
        <button
            type="button"
            aria-label="Sign out"
            title="Sign out"
            class="grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none transition hover:bg-nav-text/10 hover:text-nav-strong"
            onclick={logout}
        >
            <LogOut class="h-4 w-4" strokeWidth={1.9} />
        </button>
    </div>
{/snippet}

<div class="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
    <aside class="hidden min-h-screen flex-col bg-nav px-3 py-4 lg:flex">
        {@render nav('desktop')}
    </aside>

    <div class="min-w-0">
        <!-- Everything behind the drawer goes inert while it is open. Without this a
             keyboard user tabs straight past the drawer's last item into the page
             content, which is still focusable behind the backdrop. `inert` also hides
             it from assistive technology, which a manual Tab trap would not. -->
        <div inert={drawerOpen || undefined}>
            <header class="flex items-center justify-between border-b border-border bg-surface-raised px-4 py-2.5 lg:hidden">
                <button
                    bind:this={menuButton}
                    type="button"
                    class="flex items-center gap-2 rounded-sm bg-transparent px-1 py-1 text-sm font-semibold text-text shadow-none"
                    aria-label="Open navigation"
                    aria-expanded={drawerOpen}
                    onclick={openDrawer}
                >
                    <Menu class="h-5 w-5" strokeWidth={1.9} />
                    <span class="font-condensed tracking-[0.06em] uppercase">App Manager</span>
                </button>
                <ThemeToggle />
            </header>

            {@render children?.()}
        </div>

        {#if drawerOpen}
            <div
                class="fixed inset-0 z-40 bg-nav/60 lg:hidden"
                role="presentation"
                onclick={closeDrawer}
            ></div>
            <!-- A div, not an <aside>: <aside> is a non-interactive landmark and
                 Svelte rejects an interactive role on it
                 (a11y_no_noninteractive_element_to_interactive_role). A modal
                 navigation drawer is a dialog, not a complementary landmark, and the
                 nav snippet inside already carries its own <nav aria-label="Main">. -->
            <div
                class="fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-nav px-3 py-4 lg:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="Navigation"
            >
                <button
                    bind:this={closeButton}
                    type="button"
                    class="mb-2 ml-auto grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none"
                    aria-label="Close navigation"
                    onclick={closeDrawer}
                >
                    <X class="h-4 w-4" strokeWidth={1.9} />
                </button>
                {@render nav('mobile')}
            </div>
        {/if}
    </div>
</div>
```

Note the last line: the shell's default slot becomes a `children` snippet under runes. Add `children` to the `$props()` destructuring in Step 1's script block — the full destructuring is:

```js
let { auth, teams = [], currentTeam = null, active = 'apps', children } = $props();
```

Consumers pass content as ordinary child markup, which Svelte 5 supplies as `children`, so no call site changes.

- [ ] **Step 2: Drawer open/close, focus handling, and dismissal**

Append to the script block. `tick` must be added to the imports: `import { tick } from 'svelte';`

```js
    let menuButton = $state(null);
    let closeButton = $state(null);

    /*
     * Focus moves into the drawer on open and back to the trigger on close.
     * Without the first, a screen-reader user gets no signal the drawer appeared;
     * without the second, dismissing it drops focus to <body> and the user
     * restarts from the top of the document. `await tick()` is required because
     * the element to focus does not exist (or is still inert) until Svelte has
     * flushed the DOM update.
     */
    const openDrawer = async () => {
        drawerOpen = true;
        await tick();
        closeButton?.focus();
    };

    const closeDrawer = async () => {
        drawerOpen = false;
        await tick();
        menuButton?.focus();
    };

    // Inertia swaps the page without unmounting the shell, so the drawer would
    // otherwise stay open on top of the page the user just navigated to. Set the
    // flag directly rather than calling closeDrawer: focus belongs to the new
    // page, not back on the menu button.
    $effect(() => router.on('navigate', () => (drawerOpen = false)));

    /*
     * Crossing to the desktop breakpoint hides the drawer with `lg:hidden` but
     * leaves drawerOpen true, which would keep the page content inert with no
     * visible drawer to dismiss. Close it on the transition.
     */
    $effect(() => {
        const desktop = window.matchMedia('(min-width: 1024px)');
        const onChange = (event) => {
            if (event.matches) drawerOpen = false;
        };
        desktop.addEventListener('change', onChange);
        return () => desktop.removeEventListener('change', onChange);
    });

    const onKeydown = (event) => {
        if (event.key === 'Escape' && drawerOpen) closeDrawer();
    };
```

Note the first `$effect` returns `router.on(...)`'s own return value directly, which is Inertia's unsubscribe function — that is the effect's cleanup.

And add a `<svelte:window>` tag just above the top-level `<div>`:

```svelte
<svelte:window onkeydown={onKeydown} />
```

- [ ] **Step 3: Build and confirm every consumer still compiles**

Run: `cd apps/platform && npx vite build 2>&1 | tail -20`

Expected: build succeeds. The ten files importing `AppShell` — directly (`ApiKeys/Index`, `Apps/AppLayout`, `Billing/Index`, `Profile/Edit`, `Team/Index`) or through `WorkspacePage` — must produce no errors. Svelte 5 warnings about legacy `export let` in those still-unmigrated pages are expected and are not failures.

- [ ] **Step 4: Verify in the browser at both widths**

With the dev server running, use Playwright to confirm:

1. At 1440px: the sidebar is visible, the active item is filled in `brand`, and the theme toggle sits in the footer next to sign-out.
2. At 390px: the sidebar is hidden and the header shows a working menu button. Click it — the drawer opens with all navigation items. Click a link — the drawer closes and the page navigates.
3. At 390px: open the drawer and press Escape — it closes.
4. Tab through the sidebar and confirm a visible ring on every link, the workspace select, the theme toggle, and sign-out.
5. Repeat 1 and 2 in the dark theme.

- [ ] **Step 5: Confirm the guards**

Run:

```bash
cd apps/platform && npm run design:lint && npm run test:design
```

Expected: both pass. `AppShell.svelte` is not yet in the linted paths — add `resources/js/Components/AppShell.svelte` to the `design:lint` script argument list in `package.json`, then re-run and expect it clean.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/resources/js/Components/AppShell.svelte apps/platform/package.json
git commit -m "feat(design): rewrite the app shell on tokens, add mobile navigation

Converted to runes; the nav item list replaces eight copies of the same
Link markup. Adds a drawer below lg: the sidebar was hidden there and the
mobile header held only the logo and sign-out, so there was no way to
navigate at all under 1024px. Hosts the theme toggle."
```

---

## Task 7: `PageHeader` and the rewritten `WorkspacePage`

**Files:**
- Create: `resources/js/Components/ui/PageHeader.svelte`
- Modify: `resources/js/Components/WorkspacePage.svelte` (full rewrite, currently 47 lines)

**Interfaces:**
- Consumes: tokens from Task 2, `AppShell` from Task 6.
- Produces: `PageHeader` accepting `let { eyebrow = '', title = '', description = '', breadcrumbs, actions } = $props()`, where `breadcrumbs` and `actions` are optional snippets.
- Produces: `WorkspacePage` accepting `let { auth, navigationTeams = [], navigationTeam = null, active = '', eyebrow = '', title = '', description = '', width = 'default', flash = {}, breadcrumbs, actions, heading, children } = $props()`. `width` is `'default'` (1240px) or `'narrow'` (720px). The existing five consumers pass no `description`, `width`, or `breadcrumbs` and keep working.

- [ ] **Step 1: Write `PageHeader`**

Create `resources/js/Components/ui/PageHeader.svelte`:

```svelte
<script>
    /**
     * The one page header. Every workspace page uses this — the two competing
     * header implementations it replaces differed in title size, weight, margin,
     * and whether a description was possible at all.
     */
    let { eyebrow = '', title = '', description = '', breadcrumbs, actions } = $props();
</script>

{#if breadcrumbs}
    <div class="mb-4">{@render breadcrumbs()}</div>
{/if}

<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        {#if eyebrow}
            <p class="font-condensed text-2xs font-semibold tracking-[0.18em] text-faint uppercase">{eyebrow}</p>
        {/if}
        <h1 class="mt-1.5 font-condensed text-3xl font-semibold text-text uppercase">{title}</h1>
        {#if description}
            <p class="mt-1.5 max-w-[46ch] text-base text-muted">{description}</p>
        {/if}
    </div>
    {#if actions}
        <div class="flex flex-wrap items-center gap-2">{@render actions()}</div>
    {/if}
</div>
```

- [ ] **Step 2: Rewrite `WorkspacePage`**

Replace the entire contents of `resources/js/Components/WorkspacePage.svelte`:

```svelte
<script>
    import { CircleCheck } from '@lucide/svelte';
    import AppShell from './AppShell.svelte';
    import PageHeader from './ui/PageHeader.svelte';

    /**
     * Standard workspace page layout.
     *
     * `width` is 'default' (1240px, list and table pages) or 'narrow' (720px,
     * single-column forms). Two named widths replace the three ad-hoc container
     * widths the pages used to pick individually.
     */
    let {
        auth,
        navigationTeams = [],
        navigationTeam = null,
        active = '',
        eyebrow = '',
        title = '',
        description = '',
        width = 'default',
        flash = {},
        breadcrumbs,
        actions,
        heading,
        children,
    } = $props();

    const shell = $derived(width === 'narrow' ? 'max-w-[720px]' : 'max-w-[1240px]');
</script>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} {active}>
    <main class="mx-auto {shell} px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
        {#if heading}
            {#if breadcrumbs}<div class="mb-4">{@render breadcrumbs()}</div>{/if}
            <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">{@render heading()}</div>
                {#if actions}<div class="flex flex-wrap items-center gap-2">{@render actions()}</div>{/if}
            </div>
        {:else}
            <PageHeader {eyebrow} {title} {description} {breadcrumbs} {actions} />
        {/if}

        {#if flash?.status}
            <div class="mb-5 flex items-center gap-2.5 rounded-md border border-ok/30 bg-ok/10 px-4 py-3 text-sm font-medium text-ok" role="status">
                <CircleCheck class="h-4 w-4 shrink-0" strokeWidth={2} />
                {flash.status}
            </div>
        {/if}

        {@render children?.()}
    </main>
</AppShell>
```

The `heading` snippet escape hatch is kept because `Tools/PushNotifications` renders a title with an inline badge; it is not a second header implementation, since it reuses the same wrapper, margins, and actions row.

- [ ] **Step 3: Migrate the five existing consumers' slot syntax**

The five pages using `WorkspacePage` pass content with Svelte 4 slot syntax (`<div slot="actions">`), which runes-mode snippets do not accept. In each of `resources/js/Pages/Dashboard.svelte`, `Pages/Credentials/Index.svelte`, `Pages/AuditLog/Index.svelte`, `Pages/Integrations/Index.svelte`, and `Pages/Tools/PushNotifications.svelte`, convert only the `WorkspacePage` slot usage — leave the rest of each page alone. A `<div slot="actions">…</div>` becomes:

```svelte
{#snippet actions()}
    …the same markup, without the slot attribute…
{/snippet}
```

Likewise `slot="breadcrumbs"` and `slot="heading"`. Default content stays as plain children and needs no change.

These five pages remain in legacy mode otherwise, which is fine: a snippet passed to a runes-mode child from a legacy-mode parent is supported. Converting the page bodies to runes happens in sub-project 2.

- [ ] **Step 4: Build and confirm all five pages compile**

Run: `cd apps/platform && npx vite build 2>&1 | tail -20`

Expected: build succeeds with no "slot" or "snippet" errors.

- [ ] **Step 5: Verify the five pages in the browser**

With the dev server running, use Playwright to load each of the applications, credentials, audit log, integrations, and push-notifications pages and confirm for each: the title renders in condensed uppercase at 30px, the eyebrow is above it, the actions button group sits on the right, and no layout is broken. Check applications and credentials in both themes.

Do not expect these pages to look finished — their tables, cards, and buttons are still on the old classes. What is being verified is that the shell and header are correct and nothing is broken.

- [ ] **Step 6: Confirm the PHP tests and guards**

Run:

```bash
cd apps/platform && php artisan test 2>&1 | tail -5 && npm run design:lint && npm run test:design
```

Expected: 105 PHP tests pass; both guards pass. Add `resources/js/Components/WorkspacePage.svelte` to the `design:lint` paths and re-run.

- [ ] **Step 7: Commit**

```bash
git add apps/platform/resources/js/Components/ui/PageHeader.svelte apps/platform/resources/js/Components/WorkspacePage.svelte apps/platform/resources/js/Pages/Dashboard.svelte apps/platform/resources/js/Pages/Credentials/Index.svelte apps/platform/resources/js/Pages/AuditLog/Index.svelte apps/platform/resources/js/Pages/Integrations/Index.svelte apps/platform/resources/js/Pages/Tools/PushNotifications.svelte apps/platform/package.json
git commit -m "feat(design): one page header, two named container widths

PageHeader supports a description, which is what the five pages that
bypassed WorkspacePage were missing. Converts the five consumers' slots
to snippets; their bodies stay legacy until sub-project 2."
```

---

## Task 8: Verification sweep and a design reference page

**Files:**
- Create: `docs/design/blueprint-reference.md`
- Modify: `package.json` (add a `verify` script)

**Interfaces:**
- Consumes: everything above.
- Produces: `npm run verify` running `test:design`, `design:lint`, and `vite build` in sequence.
- Produces: a written reference recording the tokens, scale, and rules for whoever implements sub-projects 2–4.

- [ ] **Step 1: Add the combined verify script**

In `package.json` scripts:

```json
"verify": "npm run test:design && npm run design:lint && vite build"
```

- [ ] **Step 2: Screenshot every page in both themes at both widths**

With the dev server running, use Playwright to capture the ten pages reachable in the demo workspace — applications, an application overview, a build detail, credentials, API keys, audit log, integrations (all three tabs), team access, push notifications, and profile — at 1440px and 390px, in light and dark.

For each screenshot confirm: no unstyled text, no invisible text, the sidebar renders in `nav`, and at 390px the page body does not scroll horizontally.

Record any page that looks broken rather than merely unmigrated. An unmigrated page has old rounded corners and indigo buttons — that is expected. A broken page has unreadable text, overlapping elements, or horizontal overflow, and needs a fix in this task.

- [ ] **Step 3: Confirm the whole verification set passes**

Run:

```bash
cd apps/platform && npm run verify && php artisan test 2>&1 | tail -5
```

Expected: design tests pass, linter clean on the migrated paths, build succeeds, 105 PHP tests pass.

- [ ] **Step 4: Write the reference**

Create `docs/design/blueprint-reference.md` containing: the full semantic token table with both themes and the measured contrast ratio for each checked pair; the seven type steps with their pixel sizes and stated uses; the three radii and what each is for; the rule that condensed uppercase appears only in page titles and structural labels; the rule that machine-generated values are always mono; the runway's fixed 365-day denominator and the dashed no-expiry treatment; and a pointer to `npm run verify`. State that `resources/css/tokens.css` is the source of truth and that changing a value there requires re-running `npm run test:design`.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/package.json docs/design/blueprint-reference.md
git commit -m "docs(design): record the Blueprint reference and add npm run verify

Tokens with measured contrast ratios, the seven type steps, the three
radii, and the rules that the linter cannot express. Written for whoever
picks up the page migrations."
```

---

## Self-Review

**Spec coverage.** Every requirement in the spec maps to a task: the Blueprint palette and its two corrected values to Task 1; the type scale, radius scale, and semantic mapping to Task 2; self-hosted IBM Plex to Task 3; the drift guard (done criterion 7) to Task 4; theme switching and the no-flash boot (criterion 4) to Task 5; the shell and the focus ring (criterion 2) to Tasks 2 and 6; the single page header and two container widths to Task 7; contrast verification (criterion 1) to Task 1; reduced motion (criterion 3) to Task 2; Playwright screenshots (criterion 5) to Tasks 6, 7, and 8; PHP tests green (criterion 6) to Tasks 7 and 8.

**Deliberately deferred to sub-project 2, as the spec's ordering constraint requires:** removal of the `@layer base` element selectors and the `@layer components` classes. Deleting them now would leave nine unmigrated pages with unstyled buttons and inputs.

**Not in this plan and not in the spec:** the sixteen primitives (`Button`, `Badge`, `Card`, `DataTable`, `Runway`, `FilterBar`, `Modal`, `Field`, `EmptyState`, `StatTile`, `KeyValue`, `TabSelector`) and the Credentials Vault rebuild. Those need their own plan, written after this one lands — the primitives only prove themselves through a real page, so they belong with the pilot rather than split from it. This plan deliberately stops at the point where every existing page works on the new tokens, fonts, shell, and header.

**Type consistency.** `AppShell` takes `auth`, `teams`, `currentTeam`, `active`, `children` in Tasks 6; `WorkspacePage` passes exactly those in Task 7. `theme.js` exports `theme`, `setTheme`, `toggleTheme`, `THEME_STORAGE_KEY` in Task 5 Step 1; `ThemeToggle` imports `theme` and `toggleTheme` in Step 3, and the boot script in Step 2 uses the same literal storage key `'app-manager-theme'`. `contrast.mjs` exports `parseTokens`, `luminance`, `contrast`, `PAIRS` in Task 1 Step 3; the test imports exactly those in Step 1. `design-lint.mjs` exports `findViolations(source, filename)` returning `{ file, line, rule, match }` in Task 4 Step 3; the test asserts those four fields in Step 1. Token names in `PAIRS` match the custom properties in `tokens.css` one for one, and the `@theme inline` block in Task 2 maps every one of them.

**One known rough edge.** Task 2 Step 2's utility greps depend on Tailwind having something to tree-shake from, so the step includes an explicit temporary-markup procedure rather than pretending the greps pass on an untouched tree. Task 6 Step 5 and Task 7 Step 6 each widen the `design:lint` path list, so the script argument grows across tasks by design.
