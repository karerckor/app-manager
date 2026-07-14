# Edit & Delete Project Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a team member edit a project's name/description and delete the project from its detail page at `/accounts/{team:slug}/apps/{mobileApp:slug}`.

**Architecture:** Add web `update`/`destroy` actions to the existing `App\Http\Controllers\MobileAppController` (returning Inertia redirects, mirroring the JSON logic already in `Api\V1\MobileAppController`). Expose their route URLs in the `Apps/Show` Inertia payload and drive them from a new edit modal in `Apps/Show.svelte`.

**Tech Stack:** Laravel 13, Inertia.js, Svelte 5, PHPUnit feature tests.

## Global Constraints

- All work is inside `apps/platform/` (the Laravel package). Paths below are relative to that directory unless noted.
- `slug` is **out of scope**: it is never validated, updated, or recomputed by the new actions.
- New actions guard access with the existing `authorizeTeam($request, $team)` private method (aborts 403 for non-members).
- Route model binding is scoped (`scopeBindings()`), so an app that does not belong to the URL's team yields 404 automatically.
- Match existing code style: private helpers, `$request->validate([...])`, redirects with `->with('status', '...')`.
- Run tests with: `php artisan test --filter=<TestClass>` from `apps/platform/`.

---

### Task 1: Web `update` action (name + description)

**Files:**
- Modify: `routes/web.php` (add PATCH route in the `accounts.{team:slug}` group, near `apps.show` at line 30)
- Modify: `app/Http/Controllers/MobileAppController.php` (add `update` method; add `update` URL to the `app.urls` array in `show`, currently lines 89-94)
- Test: `tests/Feature/PlatformDashboardTest.php`

**Interfaces:**
- Consumes: existing `authorizeTeam(Request $request, Team $team): void`, route `accounts.apps.show`.
- Produces: route `accounts.apps.update` (PATCH, params `team` + `mobileApp`); `app.urls.update` string in the `Apps/Show` payload.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/PlatformDashboardTest.php`:

```php
    public function test_user_can_update_an_application(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Old name',
            'slug' => 'old-name',
            'description' => 'Old description',
        ]);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => 'New name',
            'description' => 'New description',
        ])->assertRedirect(route('accounts.apps.show', [$team, $app]))
            ->assertSessionHas('status', 'Application updated.');

        $this->assertDatabaseHas('mobile_apps', [
            'id' => $app->id,
            'name' => 'New name',
            'description' => 'New description',
            'slug' => 'old-name',
        ]);
    }

    public function test_update_requires_a_name(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Keep me']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => '',
            'description' => 'whatever',
        ])->assertSessionHasErrors('name');

        $this->assertSame('Keep me', $app->fresh()->name);
    }

    public function test_update_ignores_a_submitted_slug(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'stable-slug']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => 'Renamed',
            'slug' => 'hacked-slug',
        ])->assertRedirect();

        $this->assertSame('stable-slug', $app->fresh()->slug);
    }

    public function test_user_cannot_update_an_app_from_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create(['name' => 'Foreign']);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$foreignApp->team, $foreignApp]), [
            'name' => 'Hijacked',
        ])->assertForbidden();

        $this->assertSame('Foreign', $foreignApp->fresh()->name);
    }

    public function test_update_url_is_exposed_on_the_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.urls.update', route('accounts.apps.update', [$team, $app])));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PlatformDashboardTest`
Expected: FAIL — `Route [accounts.apps.update] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after the `apps.show` line (line 30), add:

```php
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->name('apps.update');
```

- [ ] **Step 4: Add the `update` controller method**

In `app/Http/Controllers/MobileAppController.php`, add this method after `show()`:

```php
    public function update(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $mobileApp->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return redirect()
            ->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $mobileApp])
            ->with('status', 'Application updated.');
    }
```

- [ ] **Step 5: Expose the `update` URL in the payload**

In `show()`, inside the `'urls' => [...]` array (currently line 89), add after the `index` entry:

```php
                    'update' => route('accounts.apps.update', ['team' => $team, 'mobileApp' => $mobileApp]),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=PlatformDashboardTest`
Expected: PASS (all methods, including the pre-existing ones).

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/MobileAppController.php tests/Feature/PlatformDashboardTest.php
git commit -m "feat(apps): add web update action for projects"
```

---

### Task 2: Web `destroy` action

**Files:**
- Modify: `routes/web.php` (add DELETE route directly after the new PATCH route)
- Modify: `app/Http/Controllers/MobileAppController.php` (add `destroy` method; add `destroy` URL to the `app.urls` array in `show`)
- Test: `tests/Feature/PlatformDashboardTest.php`

**Interfaces:**
- Consumes: `authorizeTeam`, route `accounts.apps.index`.
- Produces: route `accounts.apps.destroy` (DELETE, params `team` + `mobileApp`); `app.urls.destroy` string in the `Apps/Show` payload.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/PlatformDashboardTest.php`:

```php
    public function test_user_can_delete_an_application(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->delete(route('accounts.apps.destroy', [$team, $app]))
            ->assertRedirect(route('accounts.apps.index', $team))
            ->assertSessionHas('status', 'Application deleted.');

        $this->assertDatabaseMissing('mobile_apps', ['id' => $app->id]);
    }

    public function test_user_cannot_delete_an_app_from_another_team(): void
    {
        [$user, $team] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->delete(route('accounts.apps.destroy', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();

        $this->assertDatabaseHas('mobile_apps', ['id' => $foreignApp->id]);
    }

    public function test_destroy_url_is_exposed_on_the_drilldown(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.urls.destroy', route('accounts.apps.destroy', [$team, $app])));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PlatformDashboardTest`
Expected: FAIL — `Route [accounts.apps.destroy] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after the `apps.update` line, add:

```php
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->name('apps.destroy');
```

- [ ] **Step 4: Add the `destroy` controller method**

In `app/Http/Controllers/MobileAppController.php`, add this method after `update()`:

```php
    public function destroy(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        $mobileApp->delete();

        return redirect()
            ->route('accounts.apps.index', ['team' => $team])
            ->with('status', 'Application deleted.');
    }
```

- [ ] **Step 5: Expose the `destroy` URL in the payload**

In `show()`, inside the `'urls' => [...]` array, add after the new `update` entry:

```php
                    'destroy' => route('accounts.apps.destroy', ['team' => $team, 'mobileApp' => $mobileApp]),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=PlatformDashboardTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/MobileAppController.php tests/Feature/PlatformDashboardTest.php
git commit -m "feat(apps): add web destroy action for projects"
```

---

### Task 3: Edit-application modal (frontend)

**Files:**
- Modify: `resources/js/Pages/Apps/Show.svelte`

**Interfaces:**
- Consumes: `app.name`, `app.description`, `app.urls.update`, `app.urls.destroy` (from Tasks 1 & 2); Inertia `useForm`, `router`; existing `.modal-backdrop` / `.modal` / `.label` / `.error` / `.secondary` / `.ghost` styles and the `flash.status` banner.
- Produces: an **Edit** button in the header and an Edit modal (`showEdit` state) with delete confirmation.

This task has no automated test (Svelte view). Verify manually per Step 5.

- [ ] **Step 1: Add edit state, form, and handlers**

In the `<script>` block, after the existing state declarations (`let editingCredential = null;`, line 18), add:

```js
    let showEdit = false;
    let confirmingDelete = false;

    const editForm = useForm({ name: app.name, description: app.description ?? '' });

    const openEdit = () => {
        editForm.name = app.name;
        editForm.description = app.description ?? '';
        editForm.clearErrors();
        confirmingDelete = false;
        showEdit = true;
    };
    const closeEdit = () => {
        showEdit = false;
        confirmingDelete = false;
        editForm.clearErrors();
    };
    const saveEdit = () => editForm.patch(app.urls.update, { preserveScroll: true, onSuccess: closeEdit });
    const deleteApp = () => router.delete(app.urls.destroy);
```

- [ ] **Step 2: Add the Edit button to the header**

In the header (line 186), replace the single Add button with the Add button plus an Edit button. Change:

```svelte
            <button class="flex items-center gap-2" on:click={() => identifiers.length ? openCreateCredential() : openCreateIdentifier()}>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                {identifiers.length ? 'Add credential' : 'Add identifier'}
            </button>
```

to:

```svelte
            <div class="flex items-center gap-2">
                <button type="button" class="secondary flex items-center gap-2" on:click={openEdit}>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    Edit
                </button>
                <button class="flex items-center gap-2" on:click={() => identifiers.length ? openCreateCredential() : openCreateIdentifier()}>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    {identifiers.length ? 'Add credential' : 'Add identifier'}
                </button>
            </div>
```

- [ ] **Step 3: Add the Edit modal markup**

At the end of the file (after the closing `{/if}` of the credential modal, line 386), append:

```svelte
{#if showEdit}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeEdit()}>
        <form class="modal" on:submit|preventDefault={saveEdit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">Edit application</h2><p class="mt-1 text-sm text-slate-500">Update the project name and description.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeEdit}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <label><span class="label">Name</span><input bind:value={editForm.name} required />{#if editForm.errors.name}<span class="error">{editForm.errors.name}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-slate-400">(optional)</span></span><textarea class="min-h-24" bind:value={editForm.description} placeholder="What is this project?"></textarea>{#if editForm.errors.description}<span class="error">{editForm.errors.description}</span>{/if}</label>
                <div class="rounded-xl border border-rose-200 bg-rose-50/60 p-4">
                    {#if confirmingDelete}
                        <p class="text-xs font-semibold text-rose-800">Delete this application? This can't be undone.</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" class="secondary px-3 py-2 text-xs" on:click={() => confirmingDelete = false}>Cancel</button>
                            <button type="button" class="bg-rose-600 px-3 py-2 text-xs text-white hover:bg-rose-700" on:click={deleteApp}>Confirm delete</button>
                        </div>
                    {:else}
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-xs font-bold text-rose-800">Danger zone</p><p class="mt-0.5 text-[11px] text-rose-700/80">Permanently delete this application and its data.</p></div>
                            <button type="button" class="border border-rose-300 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100" on:click={() => confirmingDelete = true}>Delete application</button>
                        </div>
                    {/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeEdit}>Cancel</button><button disabled={editForm.processing}>{editForm.processing ? 'Saving…' : 'Save changes'}</button></div>
        </form>
    </div>
{/if}
```

- [ ] **Step 4: Build the frontend**

Run: `npm run build`
Expected: build completes with no errors.

- [ ] **Step 5: Manual verification**

Start the app (`composer run dev`), open a project page, and confirm:
- The **Edit** button opens the modal pre-filled with the current name/description.
- Saving a new name/description updates the header and shows the "Application updated." banner.
- Clearing the name and saving shows an inline validation error.
- **Delete application** → **Confirm delete** removes the app and returns to the apps list with the "Application deleted." banner.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Apps/Show.svelte
git commit -m "feat(apps): edit and delete a project from its detail page"
```

---

## Self-Review

- **Spec coverage:** edit name/description → Task 1 + Task 3; delete project → Task 2 + Task 3; slug untouched → Task 1 (`test_update_ignores_a_submitted_slug`, no slug in payload); URLs in payload → Tasks 1 & 2; auth 403 → Tasks 1 & 2; UI as a modal like the others → Task 3. All spec points covered.
- **Placeholders:** none — every code and test block is complete.
- **Type consistency:** `app.urls.update` / `app.urls.destroy` produced in Tasks 1/2 and consumed in Task 3; `openEdit`/`closeEdit`/`saveEdit`/`deleteApp`/`showEdit`/`confirmingDelete` used consistently; `authorizeTeam` signature matches the existing method.
