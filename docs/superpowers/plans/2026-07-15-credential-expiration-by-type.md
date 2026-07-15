# Credential Expiration Field By Type — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the credential "Expiration" field (and accept `expires_at`) only for credential types that actually expire — iOS certificates and provisioning profiles.

**Architecture:** Add an `expires` boolean to each type's schema definition in `CredentialSchema`. The controller derives its `expires_at` validation rule from that flag (`nullable|date` vs `prohibited`) and nulls the value on type changes. The Svelte form reads the same flag (already delivered via the `credentialTypes` prop) to conditionally render the field.

**Tech Stack:** Laravel 11 (PHP 8.2), PHPUnit feature tests, Inertia + Svelte frontend, Vite.

## Global Constraints

- Working directory for all paths and commands: `apps/platform`.
- Only `IosCertificate` and `ProvisioningProfile` expire. All other credential types do not.
- Non-expiring types must reject a non-empty `expires_at` via Laravel's `prohibited` rule.
- Run PHP tests with: `./vendor/bin/phpunit` (or `php artisan test`).
- Follow existing code style; no unrelated refactoring.

---

### Task 1: Add `expires` flag to the credential schema

**Files:**
- Modify: `app/Support/Credentials/CredentialSchema.php`
- Test: `tests/Feature/CredentialSchemaTighteningTest.php`

**Interfaces:**
- Produces:
  - `CredentialSchema::definition(CredentialType $type): array` now includes key `'expires' => bool`.
  - `CredentialSchema::expires(CredentialType $type): bool` — true only for `IosCertificate` and `ProvisioningProfile`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CredentialSchemaTighteningTest.php` (class `CredentialSchemaTighteningTest`). Ensure `use App\Enums\CredentialType;` and `use App\Support\Credentials\CredentialSchema;` are present at the top (add if missing).

```php
public function test_only_apple_signing_types_are_marked_as_expiring(): void
{
    $expiring = [CredentialType::IosCertificate, CredentialType::ProvisioningProfile];

    foreach (CredentialType::cases() as $type) {
        $shouldExpire = in_array($type, $expiring, true);

        $this->assertSame($shouldExpire, CredentialSchema::definition($type)['expires']);
        $this->assertSame($shouldExpire, CredentialSchema::expires($type));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter test_only_apple_signing_types_are_marked_as_expiring`
Expected: FAIL — undefined array key `'expires'` / undefined method `expires`.

- [ ] **Step 3: Implement the schema flag and helper**

In `app/Support/Credentials/CredentialSchema.php`:

Add the `expires` parameter to `make()` and return it. Change the signature and the returned array:

```php
    private static function make(
        CredentialType $type,
        string $label,
        array $platforms,
        array $extensions,
        string $fileHint,
        array $fields = [],
        bool $profileScoped = false,
        bool $expires = false,
    ): array {
        return [
            'value' => $type->value,
            'label' => $label,
            'platforms' => $platforms,
            'attachment' => $profileScoped ? 'profile' : 'identifier',
            'expires' => $expires,
            'profiles' => $profileScoped
                ? collect($platforms)->flatMap(fn (string $platform) => BuildProfileSchema::forPlatform($platform))->values()->all()
                : [],
            'fields' => $fields,
            'file' => [
                'required' => true,
                'extensions' => $extensions,
                'accept' => implode(',', array_map(fn ($extension) => '.'.$extension, $extensions)),
                'hint' => $fileHint,
            ],
        ];
    }
```

In `definition()`, pass `expires: true` for the two Apple signing types. For `IosCertificate` the last positional arg is currently `true` (profileScoped) — switch that call to named args to add `expires`:

```php
            CredentialType::IosCertificate => self::make(
                $type,
                'iOS signing certificate',
                ['ios'],
                ['p12', 'pfx'],
                'Password-protected PKCS#12 certificate (.p12 or .pfx)',
                [
                    self::field('password', 'Certificate password', 'password'),
                ],
                profileScoped: true,
                expires: true,
            ),
            CredentialType::ProvisioningProfile => self::make(
                $type,
                'Provisioning profile',
                ['ios'],
                ['mobileprovision'],
                'Apple provisioning profile (.mobileprovision)',
                [],
                profileScoped: true,
                expires: true,
            ),
```

Add the helper method (place it next to `supportsPlatform()`):

```php
    public static function expires(CredentialType $type): bool
    {
        return self::definition($type)['expires'];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter test_only_apple_signing_types_are_marked_as_expiring`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Credentials/CredentialSchema.php tests/Feature/CredentialSchemaTighteningTest.php
git commit -m "feat(platform): mark expiring credential types in schema"
```

---

### Task 2: Enforce `expires_at` by type when creating a credential

**Files:**
- Modify: `app/Http/Controllers/CredentialController.php` (`store()`, lines ~24-89)
- Test: `tests/Feature/CredentialInputValidationTest.php`

**Interfaces:**
- Consumes: `CredentialSchema::expires(CredentialType $type): bool` from Task 1.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CredentialInputValidationTest.php`. It already has the helpers `application(Platform)`, `credentialStoreUrl(MobileApp)`, and `pkcs12(string)`, and imports `CredentialType`, `Platform`, `ProfileType`, `UploadedFile`, `Storage`.

```php
public function test_ios_certificate_accepts_an_expiration_date(): void
{
    Storage::fake('public');
    [$user, $app, $identifier] = $this->application(Platform::Ios);
    $certificate = $this->pkcs12('pw');

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::IosCertificate->value,
        'name' => 'Distribution certificate',
        'profile_type' => ProfileType::AppStore->value,
        'expires_at' => '2027-01-31',
        'payload' => ['password' => 'pw'],
        'file' => UploadedFile::fake()->createWithContent('distribution.p12', $certificate),
    ])->assertSessionHasNoErrors();

    $this->assertSame('2027-01-31', Credential::firstOrFail()->expires_at->toDateString());
}

public function test_non_expiring_type_rejects_an_expiration_date(): void
{
    Storage::fake('public');
    [$user, $app, $identifier] = $this->application(Platform::Android);

    $valid = json_encode([
        'project_info' => ['project_id' => 'demo', 'project_number' => '123'],
        'client' => [['client_info' => ['mobilesdk_app_id' => '1:123:android:abc']]],
    ], JSON_THROW_ON_ERROR);

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServicesJson->value,
        'name' => 'Android Firebase',
        'expires_at' => '2027-01-31',
        'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
    ])->assertSessionHasErrors('expires_at');

    $this->assertDatabaseCount('credentials', 0);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "test_ios_certificate_accepts_an_expiration_date|test_non_expiring_type_rejects_an_expiration_date"`
Expected: FAIL — the second test fails because `expires_at` is currently accepted for every type (`nullable|date`), so no `expires_at` error is produced.

- [ ] **Step 3: Make the `expires_at` rule type-dependent in `store()`**

In `app/Http/Controllers/CredentialController.php`, `store()`, `$type` is already resolved (`$type = CredentialType::tryFrom(...)`) before the `$rules` array is built. Replace the static rule:

```php
            'expires_at' => ['nullable', 'date'],
```

with:

```php
            'expires_at' => $type && CredentialSchema::expires($type)
                ? ['nullable', 'date']
                : ['prohibited'],
```

(No other change needed in `store()` — `Arr::only($data, ['name', 'type', 'expires_at'])` naturally omits `expires_at` for non-expiring types, and a new credential defaults to `null`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "test_ios_certificate_accepts_an_expiration_date|test_non_expiring_type_rejects_an_expiration_date"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/CredentialController.php tests/Feature/CredentialInputValidationTest.php
git commit -m "feat(platform): reject expiration on non-expiring credential creation"
```

---

### Task 3: Enforce `expires_at` by type when updating a credential

**Files:**
- Modify: `app/Http/Controllers/CredentialController.php` (`update()`, lines ~91-189)
- Test: `tests/Feature/CredentialUpdateTest.php`

**Interfaces:**
- Consumes: `CredentialSchema::expires(CredentialType $type): bool` from Task 1.

- [ ] **Step 1: Fix the existing update test that sends `expires_at` on a keystore**

`tests/Feature/CredentialUpdateTest.php::test_user_can_fully_update_a_credential_from_an_app` updates an `AndroidKeystore` (non-expiring) and sends `'expires_at' => '2027-08-09'`. Under the new rule this will now error. Remove the `expires_at` line from that request payload (line ~54) and delete the assertion on line ~69:

```php
        $this->assertSame('2027-08-09', $credential->expires_at->toDateString());
```

- [ ] **Step 2: Write the failing tests**

Add to `tests/Feature/CredentialUpdateTest.php` (helpers `application()` and `identifier()` exist; note `identifier()` creates an **Android** identifier). For the "clears on type change" test we drive an iOS provisioning-profile credential moving to a non-expiring iOS type (APNs key). Add an iOS identifier helper inline.

```php
public function test_update_rejects_expiration_on_a_non_expiring_type(): void
{
    Storage::fake('public');
    [$user, $team, $app] = $this->application();
    $identifier = $this->identifier($app, 'com.example.app');
    $credential = Credential::factory()->create([
        'team_id' => $team->id,
        'type' => CredentialType::GooglePlayServiceAccount,
        'name' => 'Play account',
    ]);
    $identifier->credentials()->attach($credential);
    $credential->addMediaFromString('{}')->usingFileName('sa.json')->toMediaCollection('file');

    $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GooglePlayServiceAccount->value,
        'name' => 'Play account',
        'expires_at' => '2027-01-31',
        'payload' => [],
    ])->assertSessionHasErrors('expires_at');
}

public function test_update_clears_expiration_when_changing_to_a_non_expiring_type(): void
{
    Storage::fake('public');
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $team->users()->attach($user, ['role' => 'owner']);
    $user->update(['current_team_id' => $team->id]);
    $app = MobileApp::factory()->create(['team_id' => $team->id]);
    $identifier = AppIdentifier::factory()->create([
        'mobile_app_id' => $app->id,
        'platform' => Platform::Ios,
        'identifier' => 'com.example.ios',
    ]);
    $credential = Credential::factory()->create([
        'team_id' => $team->id,
        'type' => CredentialType::ProvisioningProfile,
        'name' => 'Profile',
        'expires_at' => '2027-01-31',
    ]);
    $profile = $identifier->profiles()->create([
        'type' => ProfileType::AppStore,
        'name' => 'App Store',
    ]);
    $profile->credentials()->attach($credential);
    $credential->addMediaFromString('profile')->usingFileName('app.mobileprovision')->toMediaCollection('file');

    $this->actingAs($user->fresh())->post(route('accounts.credentials.update', [$team, $credential]), [
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::ApnsKey->value,
        'name' => 'Push key',
        'payload' => ['key_id' => 'ABC123DEFG', 'team_id' => 'TEAM123456'],
        'file' => UploadedFile::fake()->createWithContent('AuthKey.p8', $this->privateKey()),
    ])->assertSessionHasNoErrors();

    $this->assertNull($credential->fresh()->expires_at);
}

private function privateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);

    return $pem;
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "test_update_rejects_expiration_on_a_non_expiring_type|test_update_clears_expiration_when_changing_to_a_non_expiring_type"`
Expected: FAIL — `expires_at` is still accepted for any type, and the stored value survives a type change (`Arr::only` keeps the old value out of the update, so it is never nulled).

- [ ] **Step 4: Make the `expires_at` rule type-dependent and null it on non-expiring types in `update()`**

In `app/Http/Controllers/CredentialController.php`, `update()`, `$type` is resolved (`$type = CredentialType::tryFrom(...)`) before `$rules`. Replace the static rule:

```php
            'expires_at' => ['nullable', 'date'],
```

with:

```php
            'expires_at' => $type && CredentialSchema::expires($type)
                ? ['nullable', 'date']
                : ['prohibited'],
```

Then, inside the `DB::transaction(...)` closure, change the `$credential->update([...])` call so `expires_at` is explicitly nulled for non-expiring types (otherwise a change away from an expiring type leaves the stale value, since `Arr::only` omits the now-absent field):

```php
            $credential->update([
                ...Arr::only($data, ['name']),
                'type' => $type,
                'expires_at' => CredentialSchema::expires($type) ? ($data['expires_at'] ?? null) : null,
                'payload' => $data['payload'] ?? null,
            ]);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "test_update_rejects_expiration_on_a_non_expiring_type|test_update_clears_expiration_when_changing_to_a_non_expiring_type|test_user_can_fully_update_a_credential_from_an_app"`
Expected: PASS (all three, including the fixed existing test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CredentialController.php tests/Feature/CredentialUpdateTest.php
git commit -m "feat(platform): reject and clear expiration on non-expiring credential updates"
```

---

### Task 4: Show the Expiration field only for expiring types in the form

**Files:**
- Modify: `resources/js/Pages/Apps/Show.svelte`

**Interfaces:**
- Consumes: `selectedSchema.expires` (boolean) — delivered via the `credentialTypes` prop, which is `CredentialSchema::definitions()` (`MobileAppController.php:122`), so the flag from Task 1 is already present.

- [ ] **Step 1: Clear a stale expiration when switching to a non-expiring type**

In `selectCredentialType` (around lines 105-117), after `credentialForm.file = null;`, add:

```javascript
        if (! schema?.expires) credentialForm.expires_at = '';
```

- [ ] **Step 2: Conditionally render the Expiration field**

The "Credential type" select and the "Expiration" field currently share a `grid sm:grid-cols-2` wrapper (lines ~341-354). Restructure so the Expiration field only renders for expiring types, and the type select spans full width when it is hidden. Replace the whole block:

```svelte
                <div class="grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="label">Credential type</span>
                        <select value={credentialForm.type} on:change={changeCredentialType}>
                            {#each availableCredentialTypes as type}<option value={type.value}>{type.label}</option>{/each}
                        </select>
                        {#if credentialForm.errors.type}<span class="error">{credentialForm.errors.type}</span>{/if}
                    </label>
                    <label>
                        <span class="label">Expiration <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="date" bind:value={credentialForm.expires_at} />
                        {#if credentialForm.errors.expires_at}<span class="error">{credentialForm.errors.expires_at}</span>{/if}
                    </label>
                </div>
```

with:

```svelte
                <div class="grid gap-4 {selectedSchema?.expires ? 'sm:grid-cols-2' : ''}">
                    <label>
                        <span class="label">Credential type</span>
                        <select value={credentialForm.type} on:change={changeCredentialType}>
                            {#each availableCredentialTypes as type}<option value={type.value}>{type.label}</option>{/each}
                        </select>
                        {#if credentialForm.errors.type}<span class="error">{credentialForm.errors.type}</span>{/if}
                    </label>
                    {#if selectedSchema?.expires}
                        <label>
                            <span class="label">Expiration <span class="font-normal text-slate-400">(optional)</span></span>
                            <input type="date" bind:value={credentialForm.expires_at} />
                            {#if credentialForm.errors.expires_at}<span class="error">{credentialForm.errors.expires_at}</span>{/if}
                        </label>
                    {/if}
                </div>
```

- [ ] **Step 3: Verify the frontend compiles**

Run: `npm run build`
Expected: build succeeds with no Svelte errors.

- [ ] **Step 4: Manually verify the behavior**

Use the `verify` skill (or `run` skill) to drive the app at
`http://localhost:8000/accounts/demo-users-team/apps/example-app`:
- Open "Add credential", select **iOS signing certificate** or **Provisioning profile** → the Expiration field is shown.
- Select any other type (e.g. **App Store Connect API key**, **Firebase**, **Android keystore**) → the Expiration field is hidden and the type select spans the row.
- Switch from an expiring type (with a date entered) to a non-expiring type, then back → the previously entered date is cleared.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Apps/Show.svelte
git commit -m "feat(platform): show credential expiration field only for expiring types"
```

---

### Task 5: Full regression run

**Files:** none (verification only)

- [ ] **Step 1: Run the full platform test suite**

Run: `./vendor/bin/phpunit`
Expected: PASS — all tests green, confirming no other test relied on `expires_at` being accepted for non-expiring types.

- [ ] **Step 2: If any unexpected failures reference `expires_at`, fix them**

Search for other senders: `grep -rn "expires_at" tests/`. Any test that posts `expires_at` on a non-expiring type must have that field removed (mirroring Task 3, Step 1). Re-run `./vendor/bin/phpunit` until green. Commit any fixes:

```bash
git add tests/
git commit -m "test(platform): drop expires_at from non-expiring credential fixtures"
```

---

## Self-Review

**Spec coverage:**
- Schema `expires` flag + helper → Task 1. ✓
- Backend `store` type-dependent rule → Task 2. ✓
- Backend `update` type-dependent rule + clearing on type change → Task 3. ✓
- Frontend conditional field + clear-on-change → Task 4. ✓
- Tests for accept/reject/clear → Tasks 2, 3; regression → Task 5. ✓
- Table "Expiration" column unchanged (spec: no change needed) → not a task. ✓

**Placeholder scan:** No TBD/TODO; all code shown in full.

**Type consistency:** `CredentialSchema::expires(CredentialType): bool` and definition key `'expires'` used identically across Tasks 1-4. Route names `accounts.apps.credentials.store` and `accounts.credentials.update` match existing tests. Frontend flag `selectedSchema?.expires` matches the definition key.
