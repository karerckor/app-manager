# Delete App Identifiers and Credentials Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add UI delete buttons for app identifiers and credentials on the app show page, backed by web `destroy` routes and controller actions that mirror the existing API behavior.

**Architecture:** Add two `DELETE` web routes and `destroy` controller methods that mirror `Api\V1` destroy logic (authorize, delete in a transaction, record a webhook). Serialize a `delete_url` for identifiers and credentials in `MobileAppController`, then render inline-confirm Delete buttons in `Show.svelte`.

**Tech Stack:** Laravel 11, Inertia, Svelte, Pest/PHPUnit feature tests, spatie webhook event recorder.

## Global Constraints

- Web routes live inside the `Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')` group in `apps/platform/routes/web.php` — route names are prefixed with `accounts.`.
- Credential deletion is a **full delete** (`$credential->delete()`), mirroring `Api\V1\CredentialController@destroy`.
- Confirmation UX is **inline** (no native `confirm()`), matching the existing app-delete pattern in `Show.svelte`.
- Identifier delete is always allowed even with attached credentials (API parity); the UI only warns.
- Webhook events: `WebhookEventType::AppIdentifierDeleted` and `WebhookEventType::CredentialDeleted`, recorded via the injected `WebhookEventRecorder` exactly as the API controllers do.
- Run tests from `apps/platform`: `php artisan test --filter=<TestName>`.

---

### Task 1: Web route + controller for deleting an app identifier

**Files:**
- Modify: `apps/platform/routes/web.php` (near line 104, after `apps.identifiers.update`)
- Modify: `apps/platform/app/Http/Controllers/AppIdentifierController.php`
- Test: `apps/platform/tests/Feature/AppIdentifierDeleteTest.php` (create)

**Interfaces:**
- Produces: route name `accounts.apps.identifiers.destroy` with params `['team', 'mobileApp', 'appIdentifier']`; method `AppIdentifierController@destroy(Request $request, Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): RedirectResponse`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/AppIdentifierDeleteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIdentifierDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_can_delete_an_identifier_and_records_event(): void
    {
        [$user, $team, $app] = $this->application();
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
            ->assertRedirect()
            ->assertSessionHas('status', 'App identifier removed.');

        $this->assertDatabaseMissing('app_identifiers', ['id' => $identifier->id]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppIdentifierDeleted->value,
            'aggregate_id' => $identifier->id,
        ]);
    }

    public function test_non_member_cannot_delete_an_identifier(): void
    {
        [, $team, $app] = $this->application();
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete(route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
            ->assertForbidden();

        $this->assertDatabaseHas('app_identifiers', ['id' => $identifier->id]);
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user->fresh(), $team, $app];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppIdentifierDeleteTest`
Expected: FAIL — route `accounts.apps.identifiers.destroy` not defined (`RouteNotFoundException`).

- [ ] **Step 3: Add the route**

In `apps/platform/routes/web.php`, immediately after the `apps.identifiers.update` route (currently line 104), add:

```php
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->name('apps.identifiers.destroy');
```

- [ ] **Step 4: Implement the controller method**

In `apps/platform/app/Http/Controllers/AppIdentifierController.php`, add this method after `update()` (before the private `rules()` method):

```php
    public function destroy(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        DB::transaction(function () use ($appIdentifier, $team, $request) {
            $payload = WebhookPayload::appIdentifier($appIdentifier);
            $appIdentifier->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierDeleted,
                $appIdentifier,
                $payload,
                $request->user(),
            );
        });

        return back()->with('status', 'App identifier removed.');
    }
```

(All required imports — `WebhookEventType`, `WebhookPayload`, `DB`, `RedirectResponse`, `Request`, `AppIdentifier`, `MobileApp`, `Team` — are already present in this file.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AppIdentifierDeleteTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add apps/platform/routes/web.php apps/platform/app/Http/Controllers/AppIdentifierController.php apps/platform/tests/Feature/AppIdentifierDeleteTest.php
git commit -m "feat(identifiers): add web route to delete app identifiers"
```

---

### Task 2: Web route + controller for deleting a credential

**Files:**
- Modify: `apps/platform/routes/web.php` (near line 111, after `credentials.update`)
- Modify: `apps/platform/app/Http/Controllers/CredentialController.php`
- Test: `apps/platform/tests/Feature/CredentialDeleteTest.php` (create)

**Interfaces:**
- Produces: route name `accounts.credentials.destroy` with params `['team', 'credential']`; method `CredentialController@destroy(Request $request, Team $team, Credential $credential): RedirectResponse`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/CredentialDeleteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_can_delete_a_credential_and_records_event(): void
    {
        [$user, $team, $app] = $this->application();
        $identifier = $this->identifier($app, 'com.example.app');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Credential removed.');

        $this->assertDatabaseMissing('credentials', ['id' => $credential->id]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::CredentialDeleted->value,
            'aggregate_id' => $credential->id,
        ]);
    }

    public function test_credential_from_another_team_cannot_be_deleted(): void
    {
        [$user, $team] = $this->application();
        $otherTeam = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $otherTeam->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Foreign account',
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertNotFound();

        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user->fresh(), $team, $app];
    }

    private function identifier(MobileApp $app, string $identifier): AppIdentifier
    {
        return AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => $identifier,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CredentialDeleteTest`
Expected: FAIL — route `accounts.credentials.destroy` not defined.

- [ ] **Step 3: Add the route**

In `apps/platform/routes/web.php`, immediately after the `credentials.update` route (currently line 111, `Route::post('/credentials/{credential}', ...)`), add:

```php
        Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
```

- [ ] **Step 4: Implement the controller method**

In `apps/platform/app/Http/Controllers/CredentialController.php`, add this method after `update()` (before `upload()`):

```php
    public function destroy(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

        DB::transaction(function () use ($credential, $team, $request) {
            $payload = WebhookPayload::credential($credential);
            $credential->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::CredentialDeleted,
                $credential,
                $payload,
                $request->user(),
            );
        });

        return back()->with('status', 'Credential removed.');
    }
```

(All required imports — `WebhookEventType`, `WebhookPayload`, `DB`, `RedirectResponse`, `Request`, `Credential`, `Team` — are already present in this file.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CredentialDeleteTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add apps/platform/routes/web.php apps/platform/app/Http/Controllers/CredentialController.php apps/platform/tests/Feature/CredentialDeleteTest.php
git commit -m "feat(credentials): add web route to delete credentials"
```

---

### Task 3: Serialize delete_url for identifiers and credentials

**Files:**
- Modify: `apps/platform/app/Http/Controllers/MobileAppController.php` (identifier map ~line 120; `credentialData()` ~line 298)
- Test: `apps/platform/tests/Feature/AppShowDeleteUrlsTest.php` (create)

**Interfaces:**
- Consumes: routes `accounts.apps.identifiers.destroy` (Task 1) and `accounts.credentials.destroy` (Task 2).
- Produces: `identifiers.*.delete_url` and `identifiers.*.services.*.delete_url` (and build_target credentials `delete_url`) props on the app show page.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/AppShowDeleteUrlsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShowDeleteUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_exposes_delete_urls_for_identifiers_and_credentials(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user->fresh())
            ->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifiers.0.delete_url', route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
                ->where('identifiers.0.services.0.delete_url', route('accounts.credentials.destroy', [$team, $credential])));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppShowDeleteUrlsTest`
Expected: FAIL — `identifiers.0.delete_url` does not exist (null vs expected URL).

- [ ] **Step 3: Add delete_url to the identifier map**

In `apps/platform/app/Http/Controllers/MobileAppController.php`, in the `identifiers` map, right after the `update_url` entry (currently ends line 124), add:

```php
                'delete_url' => route('accounts.apps.identifiers.destroy', [
                    'team' => $team,
                    'mobileApp' => $mobileApp,
                    'appIdentifier' => $identifier,
                ]),
```

- [ ] **Step 4: Add delete_url to credentialData()**

In the same file, in `credentialData()`, right after the `update_url` entry (currently line 298), add:

```php
            'delete_url' => route('accounts.credentials.destroy', ['team' => $team, 'credential' => $credential]),
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AppShowDeleteUrlsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/MobileAppController.php apps/platform/tests/Feature/AppShowDeleteUrlsTest.php
git commit -m "feat(apps): serialize delete_url for identifiers and credentials"
```

---

### Task 4: Inline-confirm Delete buttons in the UI

**Files:**
- Modify: `apps/platform/resources/js/Pages/Apps/Show.svelte` (script ~line 96; credential row ~line 378; identifier row ~line 401)

**Interfaces:**
- Consumes: `identifier.delete_url` and `credential.delete_url` (Task 3); `identifier.credentials_count`.

- [ ] **Step 1: Add delete state and handlers to the script**

In `apps/platform/resources/js/Pages/Apps/Show.svelte`, immediately after the existing `deleteArtifact` handler (line 96, `const deleteArtifact = ...`), add:

```javascript
    let confirmingIdentifierDelete = null;
    let confirmingCredentialDelete = null;
    const deleteIdentifier = (identifier) => router.delete(identifier.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingIdentifierDelete = null,
    });
    const deleteCredential = (credential) => router.delete(credential.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingCredentialDelete = null,
    });
```

- [ ] **Step 2: Add the credential Delete control**

In the credential row, find the existing Edit button (line 378):

```svelte
                    <button type="button" class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => openEditCredential(credential)}>Edit</button>
```

Immediately after it, add the inline-confirm delete control:

```svelte
                    {#if confirmingCredentialDelete === credential.id}
                        <span class="text-[10px] font-semibold text-rose-700">Delete?</span>
                        <button type="button" class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => confirmingCredentialDelete = null}>Cancel</button>
                        <button type="button" class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => deleteCredential(credential)}>Confirm</button>
                    {:else}
                        <button type="button" class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => confirmingCredentialDelete = credential.id}>Delete</button>
                    {/if}
```

- [ ] **Step 3: Add the identifier Delete control**

In the identifier row (line 401), find the closing of the Edit button group:

```svelte
<button type="button" class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => openEditIdentifier(identifier)}>Edit</button></div>
```

Replace the trailing `</div>` so the delete control sits in the same flex group. The new markup:

```svelte
<button type="button" class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => openEditIdentifier(identifier)}>Edit</button>{#if confirmingIdentifierDelete === identifier.id}<span class="text-[10px] font-semibold text-rose-700">{identifier.credentials_count > 0 ? `Delete? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete?'}</span><button type="button" class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => confirmingIdentifierDelete = null}>Cancel</button><button type="button" class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => deleteIdentifier(identifier)}>Confirm</button>{:else}<button type="button" class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => confirmingIdentifierDelete = identifier.id}>Delete</button>{/if}</div>
```

- [ ] **Step 4: Build the frontend to verify it compiles**

Run (from `apps/platform`): `npm run build`
Expected: build succeeds with no Svelte compile errors.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/resources/js/Pages/Apps/Show.svelte
git commit -m "feat(ui): add inline-confirm delete buttons for identifiers and credentials"
```

---

### Task 5: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend test suite**

Run (from `apps/platform`): `php artisan test`
Expected: all tests pass, including `AppIdentifierDeleteTest`, `CredentialDeleteTest`, `AppShowDeleteUrlsTest`.

- [ ] **Step 2: Run the frontend build**

Run (from `apps/platform`): `npm run build`
Expected: success.

- [ ] **Step 3: Manual smoke check (optional)**

Start the app, open an app show page, confirm Delete buttons appear on identifiers and credentials, the inline confirm works, and deletion removes the item and shows the status flash.
```
