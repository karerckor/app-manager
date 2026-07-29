# Credentials Vault Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure `/accounts/{team}/credentials` from four stacked card layers into a single aligned table with a sticky header, a rail of filter chips carrying the counts, and a validity runway meter as the signature element.

**Architecture:** The Inertia page `Credentials/Index.svelte` is rebuilt around one CSS-grid table whose column template lives in `app.css` so the header row and data rows cannot drift. The controller gains two filter values, two stats, and a sort parameter — additive only, no migrations. `TagEditor` gains one prop so tag editing affordances stay quiet until the row is hovered or focused.

**Tech Stack:** Laravel 12 + Inertia + Svelte 5 (Svelte 4 idiom — `export let`, `$:`, `on:click`), Tailwind CSS 4 (`@theme` / `@layer components` in `resources/css/app.css`), `@lucide/svelte` icons, PostgreSQL, PHPUnit class-style tests with `AssertableInertia`.

**Spec:** `docs/superpowers/specs/2026-07-29-credentials-vault-redesign-design.md`

## Global Constraints

- All paths are relative to `apps/platform/`.
- **Match the surrounding Svelte idiom:** `export let` props, `$:` reactive statements, `on:click` handlers. Do not introduce Svelte 5 runes — no other page in this codebase uses them.
- **Dynamic components:** the installed Svelte is 5.56.6 running these files in legacy mode. `<svelte:component this={Foo} />` works but is deprecated in Svelte 5 and may emit a build warning; a plain `<Foo />` where `Foo` is a capitalised variable also works under Svelte 5. Task 4 uses `<svelte:component>` because it is valid in both modes. If `npm run build` treats the deprecation as an error, switch that one line to `{@const Icon = typeIcon(credential.type)}` plus `<Icon … />` — this codebase has no existing dynamic-component usage to copy from, so verify whichever form you use actually renders an icon rather than an empty box.
- **`@layer base { button { … } }` in `app.css` paints every `button` indigo with padding and a shadow.** Any icon or chip button must either use the `.icon-btn` / `.chip` component class from Task 2 or reset with `bg-transparent p-0 shadow-none`.
- **The table card must use `overflow-clip`, never `overflow-hidden`.** `overflow: hidden` establishes a scroll container, which makes the sticky header stick to that container instead of the viewport and silently do nothing.
- **Type floor is 10px.** The current file uses `text-[9px]` in several places; do not carry it over.
- **`expiration.status` from the server is the authority for expiration state.** Never re-derive it from `days` on the client — the server classifies day 0 as `expiring`, not `expired`. `days` only drives bar width and label text.
- Color means "needs action". No emerald in table rows. Emerald survives only in the "Encrypted at rest" badge and the `flash.status` banner.
- Every hover-revealed control must also appear on `group-focus-within` and under `@media (hover: none)`.
- Copy is sentence case, active voice, and never apologizes. Button labels match the toast they produce.
- Run PHP tests with `php artisan test`. Build the frontend with `npm run build`.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `app/Http/Controllers/CredentialVaultController.php` | filter whitelist, two new stats, sort param | 1 |
| `tests/Feature/CredentialVaultTest.php` | coverage for new filters, stats, sort | 1 |
| `resources/css/app.css` | mono font token, `.vault-grid`, `.vault-head`, `.icon-btn`, `.chip`, `.row-tools` | 2 |
| `resources/js/Components/TagEditor.svelte` | `quiet` prop | 2 |
| `resources/js/Pages/Credentials/Index.svelte` | header band, chip rail, toolbar | 3 |
| `resources/js/Pages/Credentials/Index.svelte` | table head, row anatomy, artifact icon, validity runway | 4 |
| `resources/js/Pages/Credentials/Index.svelte` | expanded row region | 5 |
| `resources/js/Pages/Credentials/Index.svelte` | empty states, read-only, responsive, a11y | 6 |

`Index.svelte` is 493 lines today and will land around the same size — the row markup shrinks while the expanded region grows. It stays one file: the page has one responsibility, and splitting it would mean threading a dozen props through wrappers for no gain. Extracting shared components is a deliberate follow-up in the spec, not part of this plan.

---

## Task 1: Controller filters, stats, and sort

**Files:**
- Modify: `app/Http/Controllers/CredentialVaultController.php:27-29` (status whitelist), `:38-75` (query), `:101-118` (filters + stats props)
- Test: `tests/Feature/CredentialVaultTest.php`

**Interfaces:**
- Consumes: nothing — this is the first task.
- Produces: props consumed by every later task —
  - `stats.unattached: int`, `stats.never: int` (alongside existing `total`, `expiring`, `expired`, `missing_files`)
  - `filters.sort: 'updated' | 'expiry'`
  - `filters.status` may now also be `'unattached'` or `'missing_file'`

- [ ] **Step 1: Write the failing tests**

Append these three methods to `tests/Feature/CredentialVaultTest.php`, before the `private function team()` helper. Add `use Illuminate\Support\Facades\Storage;` to the imports.

```php
    public function test_vault_filters_credentials_that_are_attached_to_no_application(): void
    {
        [$owner, $team] = $this->team();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas Mobile']);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);

        $viaIdentifier = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Attached to identifier',
            'type' => CredentialType::ApnsKey,
        ]);
        $identifier->credentials()->attach($viaIdentifier);

        $viaProfile = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Attached through profile',
            'type' => CredentialType::IosCertificate,
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $profile->credentials()->attach($viaProfile);

        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Orphaned keystore',
            'type' => CredentialType::AndroidKeystore,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'status' => 'unattached']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.unattached', 1)
                ->where('stats.never', 3)
                ->where('filters.status', 'unattached')
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Orphaned keystore'));
    }

    public function test_vault_filters_credentials_without_an_uploaded_secure_file(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();

        $withFile = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Distribution certificate',
            'type' => CredentialType::IosCertificate,
        ]);
        $withFile->addMediaFromString('certificate-bytes')
            ->usingFileName('dist.p12')
            ->toMediaCollection('file');

        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Keystore without a file',
            'type' => CredentialType::AndroidKeystore,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'status' => 'missing_file']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.missing_files', 1)
                ->where('filters.status', 'missing_file')
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Keystore without a file'));
    }

    public function test_vault_sorts_by_soonest_expiry_and_places_undated_credentials_last(): void
    {
        [$owner, $team] = $this->team();

        // updated_at order is deliberately the reverse of expiry order, so a
        // passing assertion cannot be explained by the default sort.
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'No expiry at all',
            'type' => CredentialType::GooglePlayServiceAccount,
            'expires_at' => null,
            'updated_at' => now(),
        ]);
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Expires in a year',
            'type' => CredentialType::ApnsKey,
            'expires_at' => now()->addDays(365),
            'updated_at' => now()->subMinute(),
        ]);
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Expires next week',
            'type' => CredentialType::IosCertificate,
            'expires_at' => now()->addDays(7),
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', ['team' => $team, 'sort' => 'expiry']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'expiry')
                ->where('credentials.data.0.name', 'Expires next week')
                ->where('credentials.data.1.name', 'Expires in a year')
                ->where('credentials.data.2.name', 'No expiry at all'));

        // Default: most recently updated first.
        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'updated')
                ->where('credentials.data.0.name', 'No expiry at all'));

        // Unknown values fall back instead of reaching orderByRaw.
        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [
                'team' => $team,
                'sort' => 'expires_at; drop table credentials',
                'status' => 'not-a-status',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'updated')
                ->where('filters.status', '')
                ->where('credentials.data.0.name', 'No expiry at all'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=CredentialVaultTest`

Expected: the three new tests FAIL. The first two fail on `Property [stats.unattached] does not exist` / wrong row counts; the third fails because `filters.sort` is missing.

- [ ] **Step 3: Widen the status whitelist and add the sort parameter**

In `CredentialVaultController::__invoke`, replace the `$status` assignment (currently lines 27-29):

```php
        $statuses = ['valid', 'expiring', 'expired', 'never', 'unattached', 'missing_file'];
        $status = in_array($request->string('status')->toString(), $statuses, true)
            ? $request->string('status')->toString()
            : '';
        $sort = in_array($request->string('sort')->toString(), ['updated', 'expiry'], true)
            ? $request->string('sort')->toString()
            : 'updated';
```

- [ ] **Step 4: Add the two query filters**

In the same method, immediately after the existing `->when($status === 'never', …)` clause, add:

```php
            ->when($status === 'unattached', fn (Builder $query) => $query
                ->whereDoesntHave('appIdentifiers')
                ->whereDoesntHave('profiles'))
            ->when($status === 'missing_file', fn (Builder $query) => $query
                ->whereDoesntHave('media', fn (Builder $query) => $query
                    ->where('collection_name', 'file')))
```

Both conditions must be checked: a credential reachable only through a `Profile` is attached, even though it has no direct `appIdentifiers` row.

- [ ] **Step 5: Make the sort configurable**

Replace `->latest('updated_at')` with:

```php
            ->when(
                $sort === 'expiry',
                fn (Builder $query) => $query->orderByRaw('expires_at IS NULL ASC, expires_at ASC'),
                fn (Builder $query) => $query->latest('updated_at'),
            )
```

On PostgreSQL `expires_at IS NULL ASC` sorts `false` before `true`, so dated credentials come first and undated ones land last. `$sort` is whitelisted in Step 3, so no request value reaches `orderByRaw`.

- [ ] **Step 6: Add the two stats and the sort filter to the props**

In the `Inertia::render` array, add `'sort' => $sort,` to the `filters` array, and add these two entries to `stats`:

```php
                'unattached' => $team->credentials()
                    ->whereDoesntHave('appIdentifiers')
                    ->whereDoesntHave('profiles')
                    ->count(),
                'never' => $team->credentials()->whereNull('expires_at')->count(),
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=CredentialVaultTest`

Expected: PASS, including the six pre-existing tests. The props contract is additive, so nothing that passed before may break.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/CredentialVaultController.php tests/Feature/CredentialVaultTest.php
git commit -m "feat(credentials): add unattached and missing-file filters, expiry sort

The vault already counted credentials with no uploaded file but offered no
way to filter by it, so the number was dead. Adds that filter plus an
unattached filter, counts for both, and a soonest-expiry sort so the
credentials that rot first can be brought to the top.

Both attachment paths are checked when deciding whether a credential is
unattached: reachable through a Profile still counts as attached.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Design tokens and the quiet tag editor

**Files:**
- Modify: `resources/css/app.css:7-10` (`@theme`), `:19-30` (`@layer components`)
- Modify: `resources/js/Components/TagEditor.svelte:6-11` (props), `:136` (remove button), `:144-154` (add button)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces, used by Tasks 3–6:
  - Tailwind utility `font-mono` resolving to the system mono stack
  - CSS classes `.vault-grid`, `.vault-head`, `.icon-btn`, `.chip`, `.row-tools`
  - `TagEditor` prop `quiet: boolean` (default `false`)

- [ ] **Step 1: Add the mono font token**

In `resources/css/app.css`, extend the `@theme` block so it reads:

```css
@theme {
    --font-sans: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
    --font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
}
```

System stack only — no webfont request. This resolves to SF Mono on macOS and Consolas on Windows.

- [ ] **Step 2: Add the component classes**

Append to the `@layer components` block in `resources/css/app.css`:

```css
    .vault-grid { @apply grid gap-3 lg:grid-cols-[minmax(0,1fr)_172px_118px_104px] lg:items-center lg:gap-4; }
    .vault-head { @apply text-[10px] font-bold tracking-[0.1em] text-slate-400 uppercase; }
    .icon-btn { @apply grid h-8 w-8 place-items-center rounded-lg bg-transparent p-0 text-slate-500 shadow-none transition hover:bg-indigo-50 hover:text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500/40 focus-visible:outline-none; }
    .row-tools { @apply opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100; }
    .chip { @apply inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-[11px] font-semibold shadow-none transition disabled:cursor-default; }
```

`.vault-grid` is the single source of truth for the column template. The header row and every data row use it, so the columns cannot drift apart. Below `lg` it collapses to one column and rows stack.

`.chip` deliberately omits colors — Task 3 supplies them per state so the active, zero-count, and severity variants stay in one readable function.

- [ ] **Step 3: Verify the CSS compiles**

Run: `npm run build`

Expected: build succeeds. If the arbitrary variant `[@media(hover:none)]:opacity-100` fails to compile inside `@apply`, drop that one utility from `.row-tools` and instead write it directly in the markup in Tasks 4–5 — do not silently omit the touch rule.

- [ ] **Step 4: Add the `quiet` prop to TagEditor**

In `resources/js/Components/TagEditor.svelte`, add to the props block:

```js
    export let quiet = false;
```

Then add a reactive class string after the existing `$: canEdit = …` line:

```js
    $: toolClass = quiet ? 'row-tools' : '';
```

- [ ] **Step 5: Apply it to the two editing affordances**

On the per-tag remove button (currently line 136), append `{toolClass}` **and make the resting `opacity-50` conditional**:

```svelte
                <button type="button" class="-mr-0.5 rounded p-0 leading-none {quiet ? '' : 'opacity-50'} shadow-none hover:opacity-100 group-hover/tag:opacity-80 {toolClass}" style="background:transparent;color:inherit" aria-label={`Remove ${tag[0]}`} disabled={saving} on:click={() => removeTag(i)}>
```

**Why `opacity-50` must be conditional, not merely accompanied by `{toolClass}`:** Tailwind 4 emits `@layer components` before `@layer utilities`, and per the CSS cascade-layers spec a later layer beats an earlier one regardless of specificity. `.row-tools` lives in `components`; `opacity-50` lives in `utilities`. So an unconditional `opacity-50` on the same element permanently overrides `.row-tools`'s `opacity:0` and all of its reveal variants — the `✕` would sit at 50% opacity forever and never hide. Emitting no `opacity-50` at all in quiet mode removes the collision instead of trying to win it.

On the dashed add button (currently line 147), append `{toolClass}` to the class attribute in the same way. Read its full class list first and confirm it sets no unconditional `opacity` utility; if it does, give it the same conditional treatment.

**General rule this establishes:** never put `.row-tools` on an element that also carries an unconditional opacity utility. Tasks 4 and 5 apply `.row-tools` to action-icon containers — keep those containers free of bare `opacity-*` classes.

`.row-tools` sets `opacity-0` plus `group-hover` / `group-focus-within` / `hover:none` overrides. It only takes effect when an ancestor carries Tailwind's `group` class, which Task 4 puts on each row. Because `quiet` defaults to `false`, every existing caller — the app identifier page, the audit log — is untouched.

- [ ] **Step 6: Verify the build and that existing tag UI is unchanged**

Run: `npm run build`

Then open any page that already uses `TagEditor` without the new prop (for example an app identifier page) and confirm the `＋ Tag` button is still visible at rest. `quiet` is opt-in; a regression here means the default leaked.

- [ ] **Step 7: Commit**

```bash
git add resources/css/app.css resources/js/Components/TagEditor.svelte
git commit -m "feat(ui): add mono type token, vault table classes, quiet tag mode

Machine data — bundle identifiers, file names, day counts, byte sizes —
gets its own voice via a system mono stack, so it stops being set in the
same face as prose. No webfont request.

.vault-grid holds the table column template in one place so a header row
and its data rows cannot drift apart. TagEditor gains an opt-in quiet
mode that hides its add and remove affordances until the containing row
is hovered or focused; existing callers are unaffected.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Header band, chip rail, and toolbar

Replaces the amber rotation banner, the four stat cards, and the filter card — three of the four layers above the list.

**Files:**
- Modify: `resources/js/Pages/Credentials/Index.svelte:38` (stats prop default), `:41-44` (filter state), `:100-121` (filter helpers), `:214-308` (header, banner, stat cards, filter card)

**Interfaces:**
- Consumes from Task 1: `stats.unattached`, `stats.never`, `filters.sort`. From Task 2: `.chip`.
- Produces, used by Tasks 4–6: `visitFilters()` now also sends `sort`; `hasFilters` also accounts for `sort`.

- [ ] **Step 1: Extend the props and filter state**

Change the `stats` prop default so the new keys have safe fallbacks:

```js
    export let stats = { total: 0, expiring: 0, expired: 0, missing_files: 0, unattached: 0, never: 0 };
```

Add the sort state next to the existing filter state, and delete nothing yet:

```js
    let sort = filters.sort ?? 'updated';
```

- [ ] **Step 2: Send sort through the filter visit**

Replace the `visitFilters` body's payload so it carries `sort`:

```js
    const visitFilters = () => router.get(credentials.path, {
        search: query.trim() || undefined,
        type: type || undefined,
        status: status || undefined,
        app: app || undefined,
        sort: sort === 'updated' ? undefined : sort,
    }, {
        only: ['credentials', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
```

Sending `undefined` for the default keeps `?sort=updated` out of the URL.

- [ ] **Step 3: Update `clearFilters` and `hasFilters`**

```js
    $: hasFilters = Boolean(query || type || status || app || sort !== 'updated');
```

```js
    const clearFilters = () => {
        query = '';
        type = '';
        status = '';
        app = '';
        sort = 'updated';
        visitFilters();
    };
```

- [ ] **Step 4: Add the chip rail definitions**

Add after the existing reactive declarations:

```js
    $: statusChips = [
        { value: '', label: 'All', count: stats.total, tone: 'neutral' },
        { value: 'expired', label: 'Expired', count: stats.expired, tone: 'rose' },
        { value: 'expiring', label: 'Expiring', count: stats.expiring, tone: 'amber' },
        { value: 'unattached', label: 'Unattached', count: stats.unattached, tone: 'amber' },
        { value: 'missing_file', label: 'No file', count: stats.missing_files, tone: 'amber' },
        { value: 'never', label: 'No expiry', count: stats.never, tone: 'neutral' },
    ];

    const chipDisabled = (chip) => Boolean(chip.value) && !chip.count;
    const chipClass = (chip) => {
        if (status === chip.value) return 'border-indigo-200 bg-indigo-50 text-indigo-700';
        if (chipDisabled(chip)) return 'border-slate-100 bg-slate-50/60 text-slate-300';
        if (chip.tone === 'rose') return 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300';
        if (chip.tone === 'amber') return 'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300';
        return 'border-slate-200 bg-white text-slate-600 hover:border-slate-300';
    };
    const applyStatus = (value) => {
        status = status === value ? '' : value;
        visitFilters();
    };
```

A chip whose count is zero is disabled, not merely dimmed: a filter that can only return an empty list should not be offered. The `All` chip has `value: ''`, so it is never disabled even in an empty vault.

- [ ] **Step 5: Replace the header band**

Replace the header block (currently lines 215-229) with:

```svelte
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-1.5">Workspace security</p>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-950">Credentials Vault</h1>
            </div>
            {#if canManage}
                <button class="flex items-center gap-2" disabled={!hasConfiguredApps} title={hasConfiguredApps ? 'Add credential' : 'Create an application identifier first'} on:click={openCreate}>
                    <Plus class="h-4 w-4" strokeWidth={2.2} />
                    Add credential
                </button>
            {:else}
                <span class="text-xs font-semibold text-slate-500">Read-only access</span>
            {/if}
        </div>
```

The prose subheading is deleted: it enumerated "Signing certificates, APNs keys, Firebase configuration, and service accounts", which the type column shows on every row. H1 drops from `text-3xl` to `text-2xl` because it no longer carries the band alone.

- [ ] **Step 6: Delete the rotation banner and the stat cards, insert the chip rail**

Delete the entire `{#if stats.expired || stats.expiring}` section (currently lines 238-249) and the entire stat-card `<section>` (currently lines 251-268). Keep the `flash.status` block between them exactly as it is.

In their place insert:

```svelte
        <div class="mb-4 flex flex-wrap items-center gap-2">
            {#each statusChips as chip (chip.value)}
                <button type="button" class="chip {chipClass(chip)}" disabled={chipDisabled(chip)} aria-pressed={status === chip.value} on:click={() => applyStatus(chip.value)}>
                    {chip.label}
                    <span class="font-mono text-[10px] font-medium tabular-nums opacity-70">{chip.count}</span>
                </button>
            {/each}
        </div>
```

The banner's content is now the rose "Expired" chip, permanently visible and immediately clickable — clicking it *is* "Review now". That removes the third copy of the same two numbers.

- [ ] **Step 7: Replace the filter card with a slim toolbar**

Replace the filter `<section class="card mb-5 p-4">` (currently lines 270-308) with:

```svelte
        <div class="mb-4 grid gap-2 md:grid-cols-[minmax(220px,1fr)_190px_180px_170px_auto]">
            <label class="relative">
                <span class="sr-only">Search credentials</span>
                <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input class="py-2 pl-9" type="search" placeholder="Search credentials, apps, identifiers…" bind:value={query} on:input={scheduleSearch} />
            </label>
            <label>
                <span class="sr-only">Credential type</span>
                <select class="py-2" bind:value={type} on:change={visitFilters}>
                    <option value="">All credential types</option>
                    <optgroup label="iOS">
                        {#each iosCredentialTypes as definition}<option value={definition.value}>{definition.label}</option>{/each}
                    </optgroup>
                    <optgroup label="Android">
                        {#each androidCredentialTypes as definition}<option value={definition.value}>{definition.label}</option>{/each}
                    </optgroup>
                </select>
            </label>
            <label>
                <span class="sr-only">Application</span>
                <select class="py-2" bind:value={app} on:change={visitFilters}>
                    <option value="">All applications</option>
                    {#each apps as item}<option value={item.id}>{item.name}</option>{/each}
                </select>
            </label>
            <label>
                <span class="sr-only">Sort order</span>
                <select class="py-2" bind:value={sort} on:change={visitFilters}>
                    <option value="updated">Recently updated</option>
                    <option value="expiry">Soonest expiry</option>
                </select>
            </label>
            {#if hasFilters}<button class="ghost px-3 py-2 text-xs" on:click={clearFilters}>Clear</button>{/if}
        </div>
```

The expiration-status `<select>` is gone — the chip rail owns status now, and two controls for one piece of state is a bug waiting to happen. The `.card` wrapper is gone too, removing one layer of white; the fields sit on the page ground.

- [ ] **Step 8: Verify the build and the page**

Run: `npm run build`

Then load `http://localhost:8000/accounts/demo-users-team/credentials` and confirm:
- No amber banner, no stat cards, no filter card outline
- Six chips with counts; `Expiring 0` and `No file 0` are dimmed and unclickable; `Expired 2` is rose
- Clicking `Expired` filters the list and marks the chip indigo; clicking it again returns to All
- `Soonest expiry` reorders the list and adds `?sort=expiry` to the URL
- `Clear` appears once any filter is set and resets everything including sort

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Credentials/Index.svelte
git commit -m "refactor(credentials): collapse vault chrome into a filter chip rail

The banner, four stat cards, and list header printed the same counts
three times, and ~620px of chrome preceded the first row. The counts now
live once, in a rail of chips that are also the status filter, so the
numbers do their own work. Zero-count chips are disabled rather than
competing with 251 for attention.

Drops the prose subheading, which enumerated what the type column already
shows on every row, and the status select, now that the chips own status.
Adds a sort control so the credentials that rot first can lead.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: The table — header row, row anatomy, artifact icon, validity runway

**Files:**
- Modify: `resources/js/Pages/Credentials/Index.svelte:4-23` (icon imports), `:68-99` (helpers), `:310-387` (list section and row markup)

**Interfaces:**
- Consumes from Task 2: `.vault-grid`, `.vault-head`, `.icon-btn`, `.row-tools`, `font-mono`, `TagEditor` `quiet`.
- Produces, used by Task 5: `expandedId` state and `toggleExpanded(credential)`; the row element carries Tailwind's `group` class so `.row-tools` resolves.

- [ ] **Step 1: Replace the icon imports**

The icon now says what kind of artifact this is, so one glyph for all nine types will not do. Replace the import block's icon list with:

```js
    import {
        BellRing,
        Braces,
        Check,
        CheckCircle2,
        ChevronDown,
        CircleUserRound,
        Clock3,
        Copy,
        Download,
        Eye,
        EyeOff,
        FileBadge,
        FileCode,
        Flame,
        KeyRound,
        LockKeyhole,
        Pencil,
        Plus,
        Search,
        ShieldCheck,
        Trash2,
        Upload,
        Vault,
        X,
    } from '@lucide/svelte';
```

`FileWarning` and `TriangleAlert` are no longer used — the stat cards and banner that needed them are gone.

All nine icon names are verified present as PascalCase exports in `node_modules/@lucide/svelte/dist/icons/index.js`.

- [ ] **Step 2: Replace `typeClass` with the two-channel mark helpers**

Delete the `typeClass` function (currently lines 93-99) and add:

```js
    const TYPE_ICONS = {
        ios_certificate: ShieldCheck,
        provisioning_profile: FileBadge,
        apns_key: BellRing,
        apple_api_key: KeyRound,
        android_keystore: Vault,
        firebase_config: Flame,
        google_play_service_account: CircleUserRound,
        google_services_json: Braces,
        google_service_info_plist: FileCode,
    };
    const typeIcon = (value) => TYPE_ICONS[value] ?? KeyRound;

    const markPlatform = (credential) => {
        const platforms = [...new Set(credential.attachments.map((item) => item.platform))];
        if (platforms.length === 1) return platforms[0];
        if (platforms.length === 0) {
            const supported = schemaFor(credential.type)?.platforms ?? [];
            if (supported.length === 1) return supported[0];
        }
        return 'mixed';
    };
    const markClass = (credential) => ({
        ios: 'bg-slate-900 text-white',
        android: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    }[markPlatform(credential)] ?? 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200');
```

Two channels carry two facts: **the icon says what kind of artifact it is, the color says which platform.** Today all 251 rows show the same `KeyRound`, so the icon is decoration.

The resolution order matters: attachments win over the type, because a type may support both platforms while this particular credential is only ever used by iOS identifiers.

- [ ] **Step 3: Add the validity runway helpers**

Delete `expirationClass` and `expirationCopy` (currently lines 76-92) and add:

```js
    const RUNWAY_DAYS = 90;
    const runwayWidth = ({ status: state, days }) => state === 'valid' || state === 'expiring'
        ? Math.min(100, Math.max(0, (days / RUNWAY_DAYS) * 100))
        : 0;
    const runwayFill = ({ status: state, days }) => {
        if (state === 'expiring') return 'bg-amber-500';
        return days >= RUNWAY_DAYS ? 'bg-slate-300' : 'bg-slate-400';
    };
    const validityLabel = ({ status: state, days }) => {
        if (state === 'never') return 'no expiry';
        if (state === 'expired') return `−${Math.abs(days)}d · expired`;
        if (days === 0) return 'expires today';
        if (days === 1) return 'expires tomorrow';
        return `+${days}d`;
    };
    const validityTone = (state) => ({
        expired: 'text-rose-700',
        expiring: 'text-amber-700',
    }[state] ?? 'text-slate-500');
```

`status` is destructured under a different name because the component already has a module-level `status` variable holding the active filter — shadowing it here would be a silent bug.

The server's `expiration.status` decides which branch applies. `days` only sizes the bar and writes the label. There is no client-side re-derivation, so day 0 stays `expiring` and reads "expires today" rather than `+0d`.

- [ ] **Step 4: Add expansion state**

Add next to the other `let` declarations:

```js
    let expandedId = null;
```

And with the other helpers:

```js
    const toggleExpanded = (credential) => {
        expandedId = expandedId === credential.id ? null : credential.id;
    };
```

- [ ] **Step 5: Replace the list section header and add the column header row**

Replace the list `<section>` opening and its header (currently lines 310-317) with:

```svelte
        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-slate-900">
                    Team credentials
                    <span class="ml-1 font-mono text-[11px] font-medium text-slate-400 tabular-nums">{credentials.total ?? 0}{hasFilters ? ' matching' : ''} in {navigationTeam?.name}</span>
                </h2>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700"><CheckCircle2 class="h-3.5 w-3.5" />Encrypted at rest</span>
            </div>

            {#if credentialItems.length}
                <div class="vault-head vault-grid sticky top-0 z-10 hidden border-y border-slate-100 bg-slate-50/80 px-5 py-2 backdrop-blur lg:grid">
                    <span>Credential</span>
                    <span>Used by</span>
                    <span>Validity</span>
                    <span class="sr-only">Actions</span>
                </div>
```

…and keep the existing `<div class="divide-y divide-slate-100">` wrapper that follows, unchanged.

`overflow-clip` rounds the card's corners without establishing a scroll container, so the header row can stick to the viewport. `overflow-hidden` here would silently break it.

**Row separators: one mechanism, not two.** The parent wrapper already carries `divide-y divide-slate-100`, which draws a line between rows and none after the last — exactly what a per-row `border-b … last:border-0` would reimplement. Keep the wrapper and give the `<article>` no border of its own; two mechanisms would double the hairline.

- [ ] **Step 6: Replace the row markup**

**Replace lines 321-387 only** — that is `{#each …}`, the opening `<article>`, and the inner grid up to and including the `</div>` that closes it.

**Do not touch lines 389-426.** Those are the revealed-secrets block, the delete-confirmation block, and the closing `</article>` / `{/each}`. They stay exactly where they are; Task 5 moves and restyles them. Deleting them here would silently remove the password reveal and the delete confirmation, and Task 5 would have nothing to move.

The replacement below therefore ends at the `</div>` that closes `.vault-grid` — it deliberately has no `</article>` and no `{/each}`.

```svelte
                    {#each credentialItems as credential (credential.id)}
                        <article class="group px-5 py-2.5 transition hover:bg-slate-50/60">
                            <div class="vault-grid">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg {markClass(credential)}">
                                        <svelte:component this={typeIcon(credential.type)} class="h-3.5 w-3.5" strokeWidth={1.9} />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <button type="button" class="block max-w-full truncate bg-transparent p-0 text-left text-[13px] font-semibold text-slate-900 shadow-none hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500/40 focus-visible:outline-none" aria-expanded={expandedId === credential.id} aria-controls={`vault-detail-${credential.id}`} on:click={() => toggleExpanded(credential)}>
                                            {credential.name}
                                        </button>
                                        <p class="mt-0.5 truncate text-[11px] text-slate-400">
                                            {typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} secure field${credential.fields.length === 1 ? '' : 's'}` : ''} ·
                                            {#if credential.file}<span class="font-mono">{credential.file.name}</span>{:else}<span class="font-medium text-amber-600">no secure file uploaded</span>{/if}
                                        </p>
                                        {#if credential.tags.length || canManage}
                                            <div class="mt-1">
                                                <TagEditor tags={credential.tags} url={canManage ? credential.tags_url : null} disabled={!canManage} suggestions={tagSuggestions} quiet emptyLabel="" />
                                            </div>
                                        {/if}
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    {#if credential.attachments.length}
                                        <Link href={credential.attachments[0].url} class="block min-w-0 truncate text-[11px] font-semibold text-slate-700 hover:text-indigo-700">{credential.attachments[0].app_name}</Link>
                                        <p class="truncate font-mono text-[10px] text-slate-400">{credential.attachments[0].identifier}</p>
                                        {#if credential.attachments.length > 1}
                                            <p class="font-mono text-[10px] text-slate-400">+{credential.attachments.length - 1} more</p>
                                        {/if}
                                    {:else}
                                        <span class="text-[11px] font-semibold text-amber-600">Unattached</span>
                                    {/if}
                                </div>

                                <div>
                                    {#if credential.expiration.status === 'never'}
                                        <div class="border-t border-dashed border-slate-200" aria-hidden="true"></div>
                                    {:else if credential.expiration.status === 'expired'}
                                        <div class="h-1 overflow-hidden rounded-full bg-rose-100" aria-hidden="true">
                                            <span class="block h-full w-full rounded-full bg-rose-500"></span>
                                        </div>
                                    {:else}
                                        <div class="h-1 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                            <span class="block h-full rounded-full {runwayFill(credential.expiration)} motion-safe:transition-[width] motion-safe:duration-300" style="width: {runwayWidth(credential.expiration)}%"></span>
                                        </div>
                                    {/if}
                                    <p class="mt-1.5 font-mono text-[11px] font-semibold tabular-nums {validityTone(credential.expiration.status)}">{validityLabel(credential.expiration)}</p>
                                </div>

                                <div class="flex shrink-0 items-center gap-0.5 lg:justify-self-end">
                                    <div class="row-tools flex items-center gap-0.5">
                                        {#if credential.file && canManage}
                                            <a href={credential.file.download_url} class="icon-btn" title="Download file" aria-label={`Download ${credential.name}`}><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                                        {/if}
                                        {#if canManage}
                                            <label for={`vault-file-${credential.id}`} class="icon-btn cursor-pointer" title={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                                            <input id={`vault-file-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                                            <button type="button" class="icon-btn" title="Edit credential" aria-label={`Edit ${credential.name}`} on:click={() => openEdit(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                            <button type="button" class="icon-btn text-rose-500 hover:bg-rose-50 hover:text-rose-600" title="Delete credential" aria-label={`Delete ${credential.name}`} on:click={() => confirmingDelete = credential.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                        {/if}
                                    </div>
                                    <button type="button" class="icon-btn" aria-expanded={expandedId === credential.id} aria-controls={`vault-detail-${credential.id}`} aria-label={`Details for ${credential.name}`} on:click={() => toggleExpanded(credential)}>
                                        <ChevronDown class="h-4 w-4 transition-transform motion-reduce:transition-none {expandedId === credential.id ? 'rotate-180' : ''}" strokeWidth={1.9} />
                                    </button>
                                </div>
                            </div>
```

After this edit the `{#each}` body reads: new `<article>` → new `.vault-grid` → **untouched** reveal block → **untouched** delete block → `</article>` → `{/each}`. Verify that shape before moving on; if the file no longer contains `revealedCredentialId === credential.id`, you deleted too much — restore it from `git diff` before committing.

What changed and why:

- The `USED BY` eyebrow is gone from the row — it is a column header, and it now appears once instead of 251 times.
- The violet "Shared" badge is gone: it is derivable from the Used-by cell, which already shows `+N more`. This is the one accessory removed on purpose.
- The bordered box around the action icons is gone. On 251 rows it was 251 extra rectangles.
- The file name moved into the meta line in mono; `no secure file uploaded` in amber is the only warning colour that appears there. File size moves to the expanded row in Task 5.
- The chevron stays outside `.row-tools` so there is always a visible affordance telling the reader the row opens.
- `TagEditor` renders only when there are tags or the user can manage, so read-only rows with no tags cost no vertical space. `emptyLabel` is cleared because the empty "No tags" text was noise at this density.

- [ ] **Step 7: Update the pagination range to mono**

In the pagination `<nav>`, replace the "Showing" paragraph and the page-number links so the numerals are mono and tabular:

```svelte
                            <p class="font-mono text-[11px] text-slate-400 tabular-nums">{credentials.from}–{credentials.to} of {credentials.total}</p>
```

Leave the Previous/Next/page-link markup otherwise as it is, but add `font-mono tabular-nums` to the numbered `Link` class so page numbers do not jitter between widths.

- [ ] **Step 8: Verify the build and the page**

Run: `npm run build`

Then load `http://localhost:8000/accounts/demo-users-team/credentials` and confirm:
- One column header row, sticky while the list scrolls — if it does not stick, check for `overflow-hidden` on the card
- Columns align between the header and every row
- Nine credential types show distinguishable icons; iOS marks are dark, Android marks emerald, Firebase/service-account marks neutral
- Expired rows show an empty rose track with a left cap and `−76d · expired`; a healthy row shows a grey bar with `+284d` and **no green anything**
- Action icons are invisible at rest, appear on hover, and appear when tabbing into the row
- Tag `✕` and `＋ Tag` are hidden until hover
- Tab through a row and confirm every control shows a focus ring

- [ ] **Step 9: Commit**

```bash
git add resources/js/Pages/Credentials/Index.svelte
git commit -m "feat(credentials): rebuild the vault list as an aligned table

Rows now share one grid template with a sticky column header, so 251
credentials can be scanned down a column instead of read as loose blocks.
USED BY is printed once rather than once per row.

The row icon carries information for the first time: the glyph says what
kind of artifact it is, the colour says which platform, resolved from the
credential's own attachments before the type's declared platforms.

Expiration becomes a runway bar on a fixed 90-day denominator so rows are
comparable, with the exact signed day count beside it in mono. Healthy
credentials get no colour at all — colour is reserved for what needs
action, so the two expired rows are the only red on the page.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: The expanded row

Holds everything the dense row cannot: all attachments, file size, secure fields, revealed secrets, and the delete confirmation.

**Files:**
- Modify: `resources/js/Pages/Credentials/Index.svelte` — the region between the row `</div>` and the closing `</article>` from Task 4

**Interfaces:**
- Consumes from Task 4: `expandedId`, `toggleExpanded`, `.icon-btn`. Existing state `revealedCredentialId`, `revealedSecrets`, `copiedSecret`, `confirmingDelete` and the existing reveal flow are reused unchanged.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add a relative-time helper**

Add with the other formatters:

```js
    const formatRelative = (value) => {
        if (!value) return 'never';
        const days = Math.round((Date.now() - new Date(value).getTime()) / 86_400_000);
        if (days <= 0) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 30) return `${days} days ago`;
        return formatDate(value);
    };
```

- [ ] **Step 2: Insert the expanded region**

Inside the `<article>`, directly after the closing `</div>` of the `.vault-grid`, insert:

```svelte
                            {#if expandedId === credential.id}
                                <div id={`vault-detail-${credential.id}`} class="mt-3 grid gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-2">
                                    <div>
                                        <p class="vault-head mb-1.5">Used by</p>
                                        {#if credential.attachments.length}
                                            <ul class="space-y-1">
                                                {#each credential.attachments as attachment}
                                                    <li>
                                                        <Link href={attachment.url} class="group/att inline-flex max-w-full items-center gap-1.5 text-[11px] text-slate-600 hover:text-indigo-700">
                                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {attachment.platform === 'ios' ? 'bg-slate-900' : 'bg-emerald-500'}"></span>
                                                            <span class="truncate font-semibold">{attachment.app_name}</span>
                                                            <span class="truncate font-mono text-[10px] text-slate-400">{attachment.identifier}</span>
                                                            {#if attachment.profile}<span class="rounded bg-indigo-50 px-1 py-0.5 text-[10px] font-bold text-indigo-600">{attachment.profile.label}</span>{/if}
                                                        </Link>
                                                    </li>
                                                {/each}
                                            </ul>
                                        {:else}
                                            <p class="text-[11px] font-medium text-amber-600">Not attached to an application yet. Attach it from an app identifier to use it in builds.</p>
                                        {/if}
                                    </div>

                                    <div class="space-y-3">
                                        <div>
                                            <p class="vault-head mb-1.5">Secure file</p>
                                            {#if credential.file}
                                                <p class="font-mono text-[11px] break-all text-slate-600">{credential.file.name}<span class="text-slate-400"> · {formatBytes(credential.file.size)}</span></p>
                                            {:else}
                                                <p class="text-[11px] font-medium text-amber-600">No file uploaded. Upload one to use this credential in builds.</p>
                                            {/if}
                                        </div>
                                        <div>
                                            <p class="vault-head mb-1.5">Updated</p>
                                            <p class="text-[11px] text-slate-600">{formatRelative(credential.updated_at)}</p>
                                        </div>
                                        {#if credential.reveal_url && revealedCredentialId !== credential.id}
                                            <button type="button" class="secondary inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px]" on:click={() => openReveal(credential)}>
                                                <Eye class="h-3.5 w-3.5" />
                                                Show password{credential.password_fields_count === 1 ? '' : 's'}
                                            </button>
                                        {/if}
                                    </div>
                                </div>
                            {/if}
```

The `id` matches the `aria-controls` on both the name button and the chevron from Task 4, so the relationship is announced.

- [ ] **Step 3: Move the revealed-secrets panel inside the expanded region**

Task 4 left the `{#if revealedCredentialId === credential.id}` block sitting after the grid, as a direct child of `<article>`. Cut that whole block — from `{#if revealedCredentialId === credential.id}` through its matching `{/if}` — and paste it as the **last child of the `sm:grid-cols-2` container inserted in Step 2**, immediately before that container's closing `</div>`.

Then change two things on it and nothing else:

1. On the block's outermost element, replace `mt-4` with `sm:col-span-2` so it spans both columns of the expanded grid.
2. Its markup and behaviour are otherwise untouched — password confirmation modal, `POST reveal_url`, 60-second auto-hide, copy buttons all keep working as they do today.

The delete-confirmation block stays a direct child of `<article>`, outside the expanded region: a row must be deletable without opening it. Only its copy changes, in Step 4.

Revealing a credential must also open its row. Add to `revealCredential`, right where `revealedCredentialId` is set:

```js
            revealedCredentialId = revealTarget.id;
            expandedId = revealTarget.id;
```

Otherwise a user could reveal a password into a collapsed row and see nothing.

- [ ] **Step 4: Restyle the delete confirmation**

Keep the existing `{#if confirmingDelete === credential.id}` block and its `deleteCredential` call. Update only its copy so it states the consequence plainly:

```svelte
                                    <span class="text-[11px] font-medium text-rose-700">{credential.attachments.length > 1 ? `Delete this credential permanently? ${credential.attachments.length} build targets use it.` : 'Delete this credential permanently?'}</span>
```

- [ ] **Step 5: Verify the build and the flows**

Run: `npm run build`

Then at `http://localhost:8000/accounts/demo-users-team/credentials` confirm:
- Clicking a credential name opens the region; clicking again closes it; the chevron rotates
- A credential attached to 3+ apps lists all of them, not just the first two
- File size appears here (`12 KB`), and the row above still shows only the file name
- `Show passwords` opens the confirmation modal, and on success the secrets render inside the expanded region with working copy buttons and the 60-second auto-hide
- Reveal a password on a **collapsed** row and confirm the row opens to show it
- Delete confirmation still cancels and still deletes

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Credentials/Index.svelte
git commit -m "feat(credentials): add an expanded row for credential detail

The dense row shows the first attachment and the file name; everything
else it cannot hold now lives in a region that opens in place — every
attachment rather than the first two, file size, last update, secure
fields, and revealed secrets.

Revealing a password also opens its row, so a secret can never be
decrypted into a collapsed row where nobody can see it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Empty states, responsive layout, and the accessibility floor

**Files:**
- Modify: `resources/js/Pages/Credentials/Index.svelte` — the empty-state block (currently lines 444-451) and a verification pass over everything built in Tasks 3–5

**Interfaces:**
- Consumes: everything from Tasks 2–5.
- Produces: nothing — this is the final task.

- [ ] **Step 1: Rewrite the empty states**

Replace the empty-state block with:

```svelte
            {:else}
                <div class="px-6 py-14 text-center">
                    <span class="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><ShieldCheck class="h-5 w-5" strokeWidth={1.7} /></span>
                    {#if hasFilters}
                        <h3 class="text-sm font-bold">No credentials match these filters</h3>
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500">Try a different search, or clear the filters.</p>
                        <button class="secondary mt-4" on:click={clearFilters}>Clear filters</button>
                    {:else if !hasConfiguredApps}
                        <h3 class="text-sm font-bold">Nothing in the vault yet</h3>
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500">Create an application identifier first — credentials attach to identifiers.</p>
                    {:else}
                        <h3 class="text-sm font-bold">Nothing in the vault yet</h3>
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500">Add a signing certificate, keystore, or service account to use it in builds.</p>
                        {#if canManage}<button class="mt-4" on:click={openCreate}>Add first credential</button>{/if}
                    {/if}
                </div>
            {/if}
```

Copy directs instead of selling. The old body — "Keep signing keys, certificates, Firebase files, and service accounts organized in one secure place" — described a benefit; this names the next action. The no-identifiers case gets its own message instead of a disabled button with a tooltip as the only explanation.

- [ ] **Step 2: Verify the mobile layout**

Resize the browser to 390px wide (or use the Playwright MCP `browser_resize` tool) and confirm:
- The column header row is hidden (`hidden lg:grid` from Task 4)
- Each row stacks: name → meta → tags → used by → validity → actions
- Action icons are **visible at rest** — this is the `@media (hover: none)` rule in `.row-tools`. If they are invisible, the arbitrary variant did not compile inside `@apply`; move it into the markup as noted in Task 2 Step 3
- The toolbar wraps to multiple rows and the chip rail wraps
- Nothing scrolls horizontally

- [ ] **Step 3: Verify the keyboard path**

With the mouse untouched, `Tab` from the page top and confirm:
- Every chip is reachable; disabled zero-count chips are skipped
- Tabbing into a row reveals its action icons and the tag `＋`
- Every focused control shows a visible ring
- `Enter` on a credential name opens the expanded region; `aria-expanded` flips
- `Escape` closes the reveal modal and the tag popover

- [ ] **Step 4: Verify colour is not load-bearing**

In devtools, apply a greyscale filter to the page (Rendering → Emulate vision deficiencies → Achromatopsia) and confirm every expiration state is still readable from its text: `−76d · expired`, `+12d`, `expires today`, `no expiry`. The bar is `aria-hidden` reinforcement, never the only signal.

- [ ] **Step 5: Verify reduced motion**

In devtools, Rendering → Emulate CSS `prefers-reduced-motion: reduce`, reload, and confirm the runway bars and the chevron rotation do not animate. The `motion-safe:` and `motion-reduce:` prefixes from Tasks 4–5 handle this.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`

Expected: PASS. No frontend test runner exists in this project, so the Inertia prop assertions from Task 1 plus `npm run build` are the automated floor; the checks above are the manual floor.

- [ ] **Step 7: Screenshot the result**

Capture `http://localhost:8000/accounts/demo-users-team/credentials` at 1440px and at 390px. Compare against the accepted mockup: the header band, chip rail, and toolbar should occupy roughly 280px before the first row, down from ~620px, and around 11 rows should be visible at 1440×900 instead of ~2.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Credentials/Index.svelte
git commit -m "feat(credentials): rewrite vault empty states, verify a11y floor

Empty states now name the next action instead of describing a benefit,
and the no-identifiers case gets its own message rather than leaving a
disabled button and a tooltip to explain itself.

Verified: rows stack on mobile with actions visible at rest, every
hover-revealed control is keyboard reachable, expiration reads correctly
in greyscale, and bars honour prefers-reduced-motion.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage.** Walking each spec section: color tokens → Task 2 Step 1-2 and the no-emerald rule enforced in Task 4 Step 6. Typography → Task 2 Step 1, applied in Tasks 3-5. Header band → Task 3 Step 5. Chip rail incl. dimmed zeros → Task 3 Steps 4, 6. Toolbar and removed status select → Task 3 Step 7. Table, shared grid template, sticky header, `overflow-clip` → Task 2 Step 2 and Task 4 Step 5. Row anatomy → Task 4 Step 6. Platform mark resolution order → Task 4 Step 2. Validity runway incl. the `expiration.status`-is-authority rule and `expires today` / `expires tomorrow` copy → Task 4 Step 3. Hover/focus/touch rules → Task 2 Step 2 (`.row-tools`), verified Task 6 Steps 2-3. Expanded row → Task 5. Empty states → Task 6 Step 1. Responsive → Task 6 Step 2. A11y floor → Task 6 Steps 3-5. Backend filters, stats, sort → Task 1. TagEditor prop → Task 2 Steps 4-5. Tests → Task 1 Step 1. Implementation traps: base `button` rule → Global Constraints and `.icon-btn`; `overflow-clip` → Global Constraints and Task 4 Step 5; single grid template → Task 2 Step 2; 10px floor → Global Constraints. No gaps found.

**Placeholder scan.** No "TBD", no "add appropriate error handling", no "similar to Task N". Every code step carries the actual code. Task 6's steps are verification rather than code, which is their deliverable — each names the exact devtools setting or viewport and the exact expected result.

**Type consistency.** Checked across tasks: `stats.unattached` / `stats.never` are produced in Task 1 Step 6 and consumed under the same names in Task 3 Steps 1, 4. `filters.sort` produced Task 1 Step 6, consumed Task 3 Step 1. `'missing_file'` (singular) is the filter value while `stats.missing_files` (plural) is the count — these genuinely differ and both are used correctly in Task 3 Step 4. `.row-tools`, `.icon-btn`, `.vault-grid`, `.vault-head`, `.chip` defined Task 2 Step 2 and used with those exact names in Tasks 3-5. `expandedId` / `toggleExpanded` defined Task 4 Step 4, used Task 4 Step 6 and Task 5 Steps 2-3. `vault-detail-${credential.id}` is the `aria-controls` target in Task 4 Step 6 and the `id` in Task 5 Step 2 — matching. `runwayWidth` / `runwayFill` / `validityLabel` / `validityTone` all defined Task 4 Step 3 and called with those names in Task 4 Step 6, each taking the `credential.expiration` object.

One real issue found and fixed while reviewing: `validityLabel` and its siblings destructure the server's `expiration.status` as `status`, which would shadow the component's module-level `status` variable holding the active filter. The helpers now rename it to `state` on destructure, and Task 4 Step 3 says why.

---

## Deferred (from the spec, deliberately not in this plan)

- Extracting `DataTable`, `FilterChips`, `ValidityMeter`, `PageHeader` into `Components/` and pulling Apps / AuditLog / ApiKeys onto the same pattern. Doing it here would widen the diff and risk regressions on pages that work today.
- Multi-select status, so a filter could ask for expired **and** unattached. Chips replace the whole `status` value today; combining them needs the controller to accept an array.
