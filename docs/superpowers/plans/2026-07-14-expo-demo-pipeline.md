# Expo Demo Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the `expo-hello-world` demo pipeline so `prepare` provisions icon, splash, client Firebase files and store-submission keys, and a new Fastfile builds and optionally deploys both platforms — all without EAS.

**Architecture:** Extend the App Manager API with two identifier-scoped credential types for client Firebase files, teach `prepare.mjs` to download every optional asset/credential and emit a `fastlane/context.json` manifest, seed demo data for all of it in `DemoSeeder`, and add a Fastlane `Fastfile` that consumes those outputs.

**Tech Stack:** Laravel 12 (PHP 8.3) + Spatie MediaLibrary + PHPUnit for the API; Node.js 22 ESM + `node:test` for the demo script; Ruby/Fastlane for build/deploy.

## Global Constraints

- API credential files are validated by `App\Rules\ValidCredentialFile` keyed on `App\Enums\CredentialType`; every new type MUST have a matching `CredentialSchema::definition()` entry or it is silently unvalidated.
- New credential types are **identifier-scoped** (`attachment: identifier`), file-only, with **no** payload fields.
- Credential secrets (keystore/certificate passwords) live ONLY in `credentials.json`; `context.json` never duplicates them.
- All files written by `prepare.mjs` that contain credentials use mode `0600`; generated `app.json` uses `0644`.
- `prepare.mjs` optional data is skipped silently when absent; ambiguous matches (2+) still throw, matching existing behavior.
- PHP seeding is idempotent (`updateOrCreate` + media-presence checks); tests seed twice and assert stable row counts.
- Demo API defaults: base URL `http://localhost:8000`, team `demo-users-team`, app `example-app`, api key `demo-api-key`.
- Run API tests with `php artisan test --filter <Name>` from `apps/platform`; run demo tests with `npm test` from `demos/expo-hello-world`.

---

## File Structure

**API (`apps/platform/`):**
- Modify `app/Enums/CredentialType.php` — add two enum cases.
- Modify `app/Support/Credentials/CredentialSchema.php` — add two `definition()` arms.
- Modify `app/Rules/ValidCredentialFile.php` — add two validators.
- Modify `database/seeders/DemoSeeder.php` — seed icon, splash, and four new credentials.
- Modify `tests/Feature/CredentialInputValidationTest.php` — validation tests.
- Modify `tests/Feature/CredentialSchemaTighteningTest.php` — schema definition tests.
- Modify `tests/Feature/DemoSeederTest.php` — demo-data assertions + idempotency counts.

**Demo (`demos/expo-hello-world/`):**
- Modify `scripts/prepare.mjs` — identifier selector, assets, firebase, store keys, `context.json`.
- Modify `tests/prepare.test.mjs` — regression + new-behavior tests.
- Create `fastlane/Fastfile`, `fastlane/Appfile`, `Gemfile`.
- Modify `.gitignore`, `README.md`.

---

## Task 1: API — new credential types + schema definitions

**Files:**
- Modify: `apps/platform/app/Enums/CredentialType.php`
- Modify: `apps/platform/app/Support/Credentials/CredentialSchema.php:22-92`
- Test: `apps/platform/tests/Feature/CredentialSchemaTighteningTest.php`

**Interfaces:**
- Produces: `CredentialType::GoogleServicesJson` (value `google_services_json`) and `CredentialType::GoogleServiceInfoPlist` (value `google_service_info_plist`); `CredentialSchema::definition()` entries with `platforms` `['android']` / `['ios']`, `attachment` `identifier`, extensions `['json']` / `['plist']`, empty `fields`.

- [ ] **Step 1: Write the failing test**

Add to `apps/platform/tests/Feature/CredentialSchemaTighteningTest.php` (inside the class):

```php
public function test_google_services_client_config_types_are_identifier_scoped_file_only(): void
{
    $json = \App\Support\Credentials\CredentialSchema::definition(
        \App\Enums\CredentialType::GoogleServicesJson,
    );
    $this->assertSame(['android'], $json['platforms']);
    $this->assertSame('identifier', $json['attachment']);
    $this->assertSame(['json'], $json['file']['extensions']);
    $this->assertSame([], $json['fields']);

    $plist = \App\Support\Credentials\CredentialSchema::definition(
        \App\Enums\CredentialType::GoogleServiceInfoPlist,
    );
    $this->assertSame(['ios'], $plist['platforms']);
    $this->assertSame('identifier', $plist['attachment']);
    $this->assertSame(['plist'], $plist['file']['extensions']);
    $this->assertSame([], $plist['fields']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter test_google_services_client_config_types_are_identifier_scoped_file_only`
Expected: FAIL — `GoogleServicesJson` is not a defined enum case.

- [ ] **Step 3: Add the enum cases**

In `apps/platform/app/Enums/CredentialType.php`, add after the `FirebaseConfig` case:

```php
    case GoogleServicesJson = 'google_services_json';   // Firebase client config (Android)
    case GoogleServiceInfoPlist = 'google_service_info_plist'; // Firebase client config (iOS)
```

- [ ] **Step 4: Add the schema definitions**

In `apps/platform/app/Support/Credentials/CredentialSchema.php`, add two arms to the `match ($type)` in `definition()`, after the `GooglePlayServiceAccount` arm:

```php
            CredentialType::GoogleServicesJson => self::make(
                $type,
                'Firebase google-services.json',
                ['android'],
                ['json'],
                'Firebase client config for Android (google-services.json)',
            ),
            CredentialType::GoogleServiceInfoPlist => self::make(
                $type,
                'Firebase GoogleService-Info.plist',
                ['ios'],
                ['plist'],
                'Firebase client config for iOS (GoogleService-Info.plist)',
            ),
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/platform && php artisan test --filter test_google_services_client_config_types_are_identifier_scoped_file_only`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Enums/CredentialType.php apps/platform/app/Support/Credentials/CredentialSchema.php apps/platform/tests/Feature/CredentialSchemaTighteningTest.php
git commit -m "feat(api): add client Firebase google-services credential types"
```

---

## Task 2: API — validate the new credential files

**Files:**
- Modify: `apps/platform/app/Rules/ValidCredentialFile.php`
- Test: `apps/platform/tests/Feature/CredentialInputValidationTest.php`

**Interfaces:**
- Consumes: `CredentialType::GoogleServicesJson`, `CredentialType::GoogleServiceInfoPlist` (Task 1).
- Produces: file validation — `google_services_json` requires JSON with a non-empty `project_info` object and a `client` array; `google_service_info_plist` requires a well-formed plist containing `<key>BUNDLE_ID</key>` and one of `<key>GOOGLE_APP_ID</key>` / `<key>PROJECT_ID</key>`.

- [ ] **Step 1: Write the failing tests**

Add to `apps/platform/tests/Feature/CredentialInputValidationTest.php` (inside the class):

```php
public function test_google_services_json_requires_firebase_client_structure(): void
{
    Storage::fake('public');
    [$user, $app, $identifier] = $this->application(Platform::Android);

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServicesJson->value,
        'name' => 'Android Firebase',
        'file' => UploadedFile::fake()->createWithContent('google-services.json', '{"project_info":{}}'),
    ])->assertSessionHasErrors('file');

    $valid = json_encode([
        'project_info' => ['project_id' => 'demo', 'project_number' => '123'],
        'client' => [['client_info' => ['mobilesdk_app_id' => '1:123:android:abc']]],
    ], JSON_THROW_ON_ERROR);

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServicesJson->value,
        'name' => 'Android Firebase',
        'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('credentials', ['type' => CredentialType::GoogleServicesJson->value]);
}

public function test_google_service_info_plist_requires_firebase_keys(): void
{
    Storage::fake('public');
    [$user, $app, $identifier] = $this->application(Platform::Ios);

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServiceInfoPlist->value,
        'name' => 'iOS Firebase',
        'file' => UploadedFile::fake()->createWithContent('GoogleService-Info.plist', 'not a plist'),
    ])->assertSessionHasErrors('file');

    $valid = <<<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
<key>BUNDLE_ID</key><string>com.example.app</string>
<key>GOOGLE_APP_ID</key><string>1:123:ios:abc</string>
<key>PROJECT_ID</key><string>demo</string>
</dict></plist>
PLIST;

    $this->actingAs($user)->post($this->credentialStoreUrl($app), [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServiceInfoPlist->value,
        'name' => 'iOS Firebase',
        'file' => UploadedFile::fake()->createWithContent('GoogleService-Info.plist', $valid),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('credentials', ['type' => CredentialType::GoogleServiceInfoPlist->value]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/platform && php artisan test --filter CredentialInputValidationTest`
Expected: the two new tests FAIL — the invalid uploads are accepted because the new types fall through the `match` default and are not validated.

- [ ] **Step 3: Add the validators**

In `apps/platform/app/Rules/ValidCredentialFile.php`, add two arms to the `match ($this->type)` in `validate()`, before `default => null`:

```php
            CredentialType::GoogleServicesJson => $this->validateGoogleServicesJson($contents, $fail),
            CredentialType::GoogleServiceInfoPlist => $this->validateGoogleServiceInfoPlist($contents, $fail),
```

Then add these private methods to the class:

```php
    private function validateGoogleServicesJson(string $contents, Closure $fail): void
    {
        try {
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('The google-services.json file must contain valid JSON.');

            return;
        }

        if (! is_array($json['project_info'] ?? null) || blank($json['project_info'])) {
            $fail('The google-services.json file must contain a non-empty project_info object.');

            return;
        }

        if (! is_array($json['client'] ?? null) || blank($json['client'])) {
            $fail('The google-services.json file must contain a non-empty client array.');
        }
    }

    private function validateGoogleServiceInfoPlist(string $contents, Closure $fail): void
    {
        if (@simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET) === false) {
            $fail('The GoogleService-Info.plist file must be a well-formed plist.');

            return;
        }

        $hasIdentity = str_contains($contents, '<key>GOOGLE_APP_ID</key>')
            || str_contains($contents, '<key>PROJECT_ID</key>');

        if (! str_contains($contents, '<key>BUNDLE_ID</key>') || ! $hasIdentity) {
            $fail('The GoogleService-Info.plist file must contain BUNDLE_ID and a Firebase project identifier.');
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter CredentialInputValidationTest`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Rules/ValidCredentialFile.php apps/platform/tests/Feature/CredentialInputValidationTest.php
git commit -m "feat(api): validate client Firebase google-services uploads"
```

---

## Task 3: API — round-trip test for identifier-scoped credential over the API

**Files:**
- Test: `apps/platform/tests/Feature/Api/V1/PlatformApiTest.php`

**Interfaces:**
- Consumes: the new types (Tasks 1–2), the existing API routes `api.v1.apps.credentials.store` and `.file.show`.
- Produces: confirmation that an identifier-scoped credential appears in `GET /credentials` with a populated `app_identifiers[].identifier` and a downloadable `file.url` — the exact shape `prepare.mjs` relies on.

- [ ] **Step 1: Read the existing test to match its helpers**

Run: `sed -n '1,80p' apps/platform/tests/Feature/Api/V1/PlatformApiTest.php`
Expected: note how it builds a team/app/identifier and authenticates (`withToken` or `actingAs` + `Sanctum`). Reuse the same setup helper in the next step; if the file has a `protected function` that provisions a team+app+identifier, call it, otherwise build inline as in `CredentialInputValidationTest::application()`.

- [ ] **Step 2: Write the failing test**

Add to `apps/platform/tests/Feature/Api/V1/PlatformApiTest.php` (inside the class; adapt the setup call to the file's existing helper if present):

```php
public function test_identifier_scoped_credential_is_listed_with_identifier_and_file(): void
{
    Storage::fake('public');

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

    $valid = json_encode([
        'project_info' => ['project_id' => 'demo'],
        'client' => [['client_info' => ['mobilesdk_app_id' => '1:1:android:a']]],
    ], JSON_THROW_ON_ERROR);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
        'app_identifier_id' => $identifier->id,
        'type' => CredentialType::GoogleServicesJson->value,
        'name' => 'Android Firebase',
        'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
    ])->assertCreated();

    $response = $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials")
        ->assertOk();

    $credential = collect($response->json('data'))
        ->firstWhere('type', CredentialType::GoogleServicesJson->value);

    $this->assertNotNull($credential);
    $this->assertSame('com.example.app', $credential['app_identifiers'][0]['identifier']);
    $this->assertNotNull($credential['file']['url']);

    $this->get($credential['file']['url'])->assertOk();
}
```

Ensure the `use` block imports `App\Enums\CredentialType`, `App\Enums\Platform`, `App\Models\{AppIdentifier,MobileApp,Team,User}`, `Laravel\Sanctum\Sanctum`, `Illuminate\Http\UploadedFile`, `Illuminate\Support\Facades\Storage` (add any missing).

- [ ] **Step 3: Run test to verify it fails, then passes**

Run: `cd apps/platform && php artisan test --filter test_identifier_scoped_credential_is_listed_with_identifier_and_file`
Expected: PASS immediately (the controller already supports this path). If it FAILS on a missing import or helper mismatch, fix the setup to match the file's conventions and re-run. This test is a guard for the contract `prepare.mjs` depends on — no production code changes.

- [ ] **Step 4: Commit**

```bash
git add apps/platform/tests/Feature/Api/V1/PlatformApiTest.php
git commit -m "test(api): assert identifier-scoped credential list shape for prepare"
```

---

## Task 4: prepare.mjs — identifier-scoped selector + client Firebase files

**Files:**
- Modify: `demos/expo-hello-world/scripts/prepare.mjs`
- Test: `demos/expo-hello-world/tests/prepare.test.mjs`

**Interfaces:**
- Consumes: the `/credentials` response where identifier-scoped credentials carry `app_identifiers: [{ identifier }]`.
- Produces: `selectIdentifierCredential(credentials, type, appIdentifier, requestedCredentialId, label)` returning the single match or `null` (throws on 2+); `google-services.json` / `GoogleService-Info.plist` written to the project root at mode `0600`; `expo.android.googleServicesFile` / `expo.ios.googleServicesFile` set in `app.json` when present.

- [ ] **Step 1: Write the failing test**

Add a new test to `demos/expo-hello-world/tests/prepare.test.mjs`. It reuses the existing three signing credentials and adds two identifier-scoped Firebase credentials. Append inside the file (after the existing `test(...)` block, before the `function json` helper):

```js
test('prepare downloads client Firebase files and wires app.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-fb-'));
  const googleServices = Buffer.from('{"project_info":{},"client":[]}');
  const googlePlist = Buffer.from('<plist></plist>');
  const fetchImpl = buildFetch({
    googleServices,
    googlePlist,
  });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const appJson = JSON.parse(await readFile(join(workspace, 'app.json'), 'utf8'));
  assert.equal(appJson.expo.android.googleServicesFile, './google-services.json');
  assert.equal(appJson.expo.ios.googleServicesFile, './GoogleService-Info.plist');
  assert.deepEqual(await readFile(join(workspace, 'google-services.json')), googleServices);
  assert.deepEqual(await readFile(join(workspace, 'GoogleService-Info.plist')), googlePlist);
  assert.equal(
    (await stat(join(workspace, 'google-services.json'))).mode & 0o777,
    0o600,
  );
});
```

At the top of the file replace the single-purpose inline fetch by extracting a shared `buildFetch` helper. Add this helper near the bottom (next to `json`), parameterized so absent options omit those credentials:

```js
function buildFetch(extras = {}) {
  const keystore = Buffer.from('demo-pkcs12-keystore');
  const certificate = Buffer.from('demo-ios-pkcs12-certificate');
  const provisioningProfile = Buffer.from('demo-mobileprovision');
  const base = '/api/v1/demo-users-team/apps/example-app';
  const identifierCredential = (id, type) => ({
    app_identifiers: [{ identifier: 'com.example.app' }],
    file: { name: `${id}.bin` },
    id,
    name: id,
    payload: extras[`${id}Payload`] ?? null,
    type,
  });

  return async (url) => {
    const { pathname } = new URL(url);

    if (pathname === base) {
      return json({
        data: {
          assets: extras.assets ?? { icon: null, splashscreen: null },
          description: 'Demo application for local development.',
          name: 'Example App',
          slug: 'example-app',
        },
      });
    }

    if (pathname === `${base}/identifiers`) {
      return json({
        data: [
          { id: 'identifier-1', identifier: 'com.example.app', platform: 'android', version: '2.3.0', version_code: 20300 },
          { id: 'identifier-2', identifier: 'com.example.app', platform: 'ios', version: '2.3.0', version_code: null },
        ],
      });
    }

    if (pathname === `${base}/credentials`) {
      const data = [
        { file: { name: 'production.keystore' }, id: 'credential-1', name: 'Production Keystore', payload: { key_alias: 'production', key_password: 'key-secret', store_password: 'store-secret' }, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'production' }], type: 'android_keystore' },
        { file: { name: 'distribution.p12' }, id: 'credential-2', name: 'App Store Certificate', payload: { password: 'certificate-secret' }, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'appstore' }], type: 'ios_certificate' },
        { file: { name: 'appstore.mobileprovision' }, id: 'credential-3', name: 'App Store Provisioning Profile', payload: null, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'appstore' }], type: 'provisioning_profile' },
      ];
      if (extras.googleServices) data.push(identifierCredential('google-services-json', 'google_services_json'));
      if (extras.googlePlist) data.push(identifierCredential('google-service-info-plist', 'google_service_info_plist'));
      if (extras.googlePlay) data.push(identifierCredential('google-play', 'google_play_service_account'));
      if (extras.appleKey) data.push({ ...identifierCredential('apple-api-key', 'apple_api_key'), payload: { key_id: 'ABCDE12345', issuer_id: '11111111-1111-4111-8111-111111111111' } });
      return json({ data });
    }

    const files = {
      'credential-1/file': keystore,
      'credential-2/file': certificate,
      'credential-3/file': provisioningProfile,
      'google-services-json/file': extras.googleServices,
      'google-service-info-plist/file': extras.googlePlist,
      'google-play/file': extras.googlePlay,
      'apple-api-key/file': extras.appleKey,
    };
    for (const [suffix, body] of Object.entries(files)) {
      if (body && pathname === `${base}/credentials/${suffix}`) {
        return new Response(body, { headers: { 'Content-Type': 'application/octet-stream' }, status: 200 });
      }
    }
    if (extras.assetBody && pathname === '/assets/icon') {
      return new Response(extras.assetBody.icon, { headers: { 'Content-Type': 'image/png' }, status: 200 });
    }
    if (extras.assetBody && pathname === '/assets/splash') {
      return new Response(extras.assetBody.splash, { headers: { 'Content-Type': 'image/png' }, status: 200 });
    }

    return new Response(null, { status: 404, statusText: 'Not Found' });
  };
}
```

Leave the ORIGINAL first test exactly as it is (it is the skip-behavior regression: no assets, no identifier credentials → unchanged `app.json`). Do not route it through `buildFetch`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: the new Firebase test FAILS — `googleServicesFile` is undefined and the files are not written.

- [ ] **Step 3: Add the identifier selector**

In `demos/expo-hello-world/scripts/prepare.mjs`, add this function next to `selectProfileCredential`:

```js
function selectIdentifierCredential(credentials, type, appIdentifier, requestedCredentialId, label) {
  const matches = credentials.filter(
    (credential) =>
      credential &&
      credential.type === type &&
      (!requestedCredentialId || String(credential.id) === requestedCredentialId) &&
      Array.isArray(credential.app_identifiers) &&
      credential.app_identifiers.some(
        (identifier) => identifier && identifier.identifier === appIdentifier,
      ),
  );

  if (matches.length === 0) {
    return null;
  }

  if (matches.length > 1) {
    const choices = matches.map((credential) => `${credential.name} (${credential.id})`).join(', ');
    throw new Error(
      `Multiple ${label} credentials match; select one with its APP_MANAGER_*_ID variable. Candidates: ${choices}`,
    );
  }

  return matches[0];
}
```

- [ ] **Step 4: Wire Firebase downloads into `prepare()`**

**Ordering (important):** the existing code downloads the three signing files and
then calls `const appJson = buildAppJson(app, androidIdentifier, iosIdentifier);`.
All optional selection + download + wiring for Tasks 4–5 must happen **immediately
before that `buildAppJson` call** so the wiring reaches `app.json`. Task 6's
`context.json` write comes **after** the existing writes. Insert the block below
directly above the `const appJson = buildAppJson(...)` line:

```js
  const androidFirebase = selectIdentifierCredential(
    credentials,
    'google_services_json',
    androidIdentifier.identifier,
    config.androidGoogleServicesId,
    'Android google-services.json',
  );
  const iosFirebase = selectIdentifierCredential(
    credentials,
    'google_service_info_plist',
    iosIdentifier.identifier,
    config.iosGoogleServicesId,
    'iOS GoogleService-Info.plist',
  );

  const firebaseWiring = {};
  if (androidFirebase) {
    const contents = await downloadCredential(request, appEndpoint, androidFirebase, 'Android google-services.json');
    await writeAtomically(join(cwd, 'google-services.json'), contents, 0o600);
    firebaseWiring.androidGoogleServicesFile = './google-services.json';
  }
  if (iosFirebase) {
    const contents = await downloadCredential(request, appEndpoint, iosFirebase, 'iOS GoogleService-Info.plist');
    await writeAtomically(join(cwd, 'GoogleService-Info.plist'), contents, 0o600);
    firebaseWiring.iosGoogleServicesFile = './GoogleService-Info.plist';
  }
```

Change the `buildAppJson(...)` call to pass an options object and thread `firebaseWiring` through. Update the signature:

```js
function buildAppJson(app, androidIdentifier, iosIdentifier, extras = {}) {
```

and inside, after building `expo.android`/`expo.ios`, before the description block:

```js
  if (extras.androidGoogleServicesFile) {
    expo.android.googleServicesFile = extras.androidGoogleServicesFile;
  }
  if (extras.iosGoogleServicesFile) {
    expo.ios.googleServicesFile = extras.iosGoogleServicesFile;
  }
```

Update the call site: `const appJson = buildAppJson(app, androidIdentifier, iosIdentifier, firebaseWiring);` (extras are merged again in Task 5).

Add the two optional config keys to `readConfig()`'s returned object:

```js
    androidGoogleServicesId: optionalValue(env.APP_MANAGER_ANDROID_GOOGLE_SERVICES_ID),
    iosGoogleServicesId: optionalValue(env.APP_MANAGER_IOS_GOOGLE_SERVICES_ID),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: PASS (both the original regression test and the new Firebase test).

- [ ] **Step 6: Commit**

```bash
git add demos/expo-hello-world/scripts/prepare.mjs demos/expo-hello-world/tests/prepare.test.mjs
git commit -m "feat(demo): download client Firebase files in prepare"
```

---

## Task 5: prepare.mjs — icon + splash download and wiring

**Files:**
- Modify: `demos/expo-hello-world/scripts/prepare.mjs`
- Test: `demos/expo-hello-world/tests/prepare.test.mjs`

**Interfaces:**
- Consumes: `app.assets.icon` / `app.assets.splashscreen`, each `{ mime_type, url } | null`.
- Produces: `assets/icon.<ext>` / `assets/splash.<ext>` (ext from mime type, default `png`) at mode `0644`; `expo.icon` and `expo.splash` (`{ image, resizeMode: 'contain', backgroundColor: '#ffffff' }`) set when present.

- [ ] **Step 1: Write the failing test**

Add to `demos/expo-hello-world/tests/prepare.test.mjs`:

```js
test('prepare downloads icon and splash and wires app.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-assets-'));
  const icon = Buffer.from('icon-bytes');
  const splash = Buffer.from('splash-bytes');
  const fetchImpl = buildFetch({
    assets: {
      icon: { mime_type: 'image/png', url: 'http://platform.test:8000/assets/icon' },
      splashscreen: { mime_type: 'image/png', url: 'http://platform.test:8000/assets/splash' },
    },
    assetBody: { icon, splash },
  });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const appJson = JSON.parse(await readFile(join(workspace, 'app.json'), 'utf8'));
  assert.equal(appJson.expo.icon, './assets/icon.png');
  assert.equal(appJson.expo.splash.image, './assets/splash.png');
  assert.equal(appJson.expo.splash.resizeMode, 'contain');
  assert.deepEqual(await readFile(join(workspace, 'assets/icon.png')), icon);
  assert.deepEqual(await readFile(join(workspace, 'assets/splash.png')), splash);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: the new assets test FAILS — `expo.icon` is undefined.

- [ ] **Step 3: Add an asset-download helper**

In `prepare.mjs`, add:

```js
const ASSET_EXTENSIONS = Object.freeze({
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/webp': 'webp',
});

async function downloadAsset(request, asset, label) {
  const response = await request(asset.url);
  const contents = Buffer.from(await response.arrayBuffer());

  if (contents.byteLength === 0) {
    throw new Error(`${label} returned an empty file.`);
  }

  return contents;
}

function assetExtension(mimeType) {
  return ASSET_EXTENSIONS[mimeType] ?? 'png';
}
```

- [ ] **Step 4: Wire assets into `prepare()`**

After the Firebase wiring block (Task 4), add:

```js
  const assets = app.assets && typeof app.assets === 'object' ? app.assets : {};
  const assetWiring = {};
  if (assets.icon && typeof assets.icon.url === 'string') {
    const contents = await downloadAsset(request, assets.icon, 'App icon');
    const relativePath = `assets/icon.${assetExtension(assets.icon.mime_type)}`;
    await writeAtomically(join(cwd, ...relativePath.split('/')), contents, 0o644);
    assetWiring.icon = `./${relativePath}`;
  }
  if (assets.splashscreen && typeof assets.splashscreen.url === 'string') {
    const contents = await downloadAsset(request, assets.splashscreen, 'Splash screen');
    const relativePath = `assets/splash.${assetExtension(assets.splashscreen.mime_type)}`;
    await writeAtomically(join(cwd, ...relativePath.split('/')), contents, 0o644);
    assetWiring.splash = `./${relativePath}`;
  }
```

Change the `buildAppJson` call to merge both wirings: `const appJson = buildAppJson(app, androidIdentifier, iosIdentifier, { ...firebaseWiring, ...assetWiring });`

In `buildAppJson`, after the Firebase-wiring block, add:

```js
  if (extras.icon) {
    expo.icon = extras.icon;
  }
  if (extras.splash) {
    expo.splash = { image: extras.splash, resizeMode: 'contain', backgroundColor: '#ffffff' };
  }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: PASS (all three tests).

- [ ] **Step 6: Commit**

```bash
git add demos/expo-hello-world/scripts/prepare.mjs demos/expo-hello-world/tests/prepare.test.mjs
git commit -m "feat(demo): download icon and splash in prepare"
```

---

## Task 6: prepare.mjs — store keys + fastlane/context.json

**Files:**
- Modify: `demos/expo-hello-world/scripts/prepare.mjs`
- Test: `demos/expo-hello-world/tests/prepare.test.mjs`

**Interfaces:**
- Consumes: identifier-scoped `google_play_service_account` (android) and `apple_api_key` (ios, payload `{ key_id, issuer_id }`).
- Produces: `credentials/android/google-play-service-account.json` and `credentials/ios/appstore-api-key.p8` (mode `0600`) when present; `fastlane/context.json` (mode `0600`) with `{ version, android: { package, versionCode, profileType, googlePlayJsonKeyPath|null }, ios: { bundleIdentifier, profileType, appStoreApiKey: { path, keyId, issuerId }|null } }`.

- [ ] **Step 1: Write the failing test**

Add to `demos/expo-hello-world/tests/prepare.test.mjs`:

```js
test('prepare downloads store keys and writes fastlane/context.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-store-'));
  const googlePlay = Buffer.from('{"type":"service_account"}');
  const appleKey = Buffer.from('-----BEGIN PRIVATE KEY-----demo-----END PRIVATE KEY-----');
  const fetchImpl = buildFetch({ googlePlay, appleKey });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const context = JSON.parse(await readFile(join(workspace, 'fastlane/context.json'), 'utf8'));
  assert.equal(context.version, '2.3.0');
  assert.equal(context.android.package, 'com.example.app');
  assert.equal(context.android.versionCode, 20300);
  assert.equal(context.android.profileType, 'production');
  assert.equal(context.android.googlePlayJsonKeyPath, 'credentials/android/google-play-service-account.json');
  assert.equal(context.ios.bundleIdentifier, 'com.example.app');
  assert.equal(context.ios.profileType, 'appstore');
  assert.deepEqual(context.ios.appStoreApiKey, {
    path: 'credentials/ios/appstore-api-key.p8',
    keyId: 'ABCDE12345',
    issuerId: '11111111-1111-4111-8111-111111111111',
  });
  assert.deepEqual(await readFile(join(workspace, 'credentials/android/google-play-service-account.json')), googlePlay);
  assert.deepEqual(await readFile(join(workspace, 'credentials/ios/appstore-api-key.p8')), appleKey);
  assert.equal((await stat(join(workspace, 'fastlane/context.json'))).mode & 0o777, 0o600);
});

test('prepare writes context.json with null store keys when absent', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-nostore-'));
  const fetchImpl = buildFetch({});

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const context = JSON.parse(await readFile(join(workspace, 'fastlane/context.json'), 'utf8'));
  assert.equal(context.android.googlePlayJsonKeyPath, null);
  assert.equal(context.ios.appStoreApiKey, null);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: the two new tests FAIL — `fastlane/context.json` does not exist.

- [ ] **Step 3a: Select + download the store keys (before `buildAppJson`)**

Add this block alongside the other optional selection/download blocks, i.e. **immediately before the `const appJson = buildAppJson(...)` line** (after the Task 5 asset block):

```js
  const googlePlayKey = selectIdentifierCredential(
    credentials,
    'google_play_service_account',
    androidIdentifier.identifier,
    config.googlePlayCredentialId,
    'Google Play service account',
  );
  const appleApiKey = selectIdentifierCredential(
    credentials,
    'apple_api_key',
    iosIdentifier.identifier,
    config.appleApiKeyCredentialId,
    'App Store Connect API key',
  );

  let googlePlayKeyPath = null;
  if (googlePlayKey) {
    const contents = await downloadCredential(request, appEndpoint, googlePlayKey, 'Google Play service account');
    googlePlayKeyPath = 'credentials/android/google-play-service-account.json';
    await writeAtomically(join(cwd, ...googlePlayKeyPath.split('/')), contents, 0o600);
  }

  let appleApiKeyContext = null;
  if (appleApiKey) {
    const payload = expectObject(appleApiKey.payload, 'The App Store Connect API key payload is missing.');
    if (typeof payload.key_id !== 'string' || typeof payload.issuer_id !== 'string') {
      throw new Error('The App Store Connect API key payload is missing key_id or issuer_id.');
    }
    const contents = await downloadCredential(request, appEndpoint, appleApiKey, 'App Store Connect API key');
    const path = 'credentials/ios/appstore-api-key.p8';
    await writeAtomically(join(cwd, ...path.split('/')), contents, 0o600);
    appleApiKeyContext = { path, keyId: payload.key_id, issuerId: payload.issuer_id };
  }
```

- [ ] **Step 3b: Assemble + write `context.json` (after the app.json write)**

After the existing `writeJsonAtomically(appJsonPath, appJson, 0o644)` call, add (it reads `appJson`, so it must run after `buildAppJson`):

```js
  const context = {
    version: appJson.expo.version,
    android: {
      package: androidIdentifier.identifier,
      versionCode: appJson.expo.android.versionCode,
      profileType: config.androidProfileType,
      googlePlayJsonKeyPath: googlePlayKeyPath,
    },
    ios: {
      bundleIdentifier: iosIdentifier.identifier,
      profileType: config.iosProfileType,
      appStoreApiKey: appleApiKeyContext,
    },
  };
  await writeJsonAtomically(join(cwd, 'fastlane', 'context.json'), context, 0o600);
```

The `googlePlayKeyPath` / `appleApiKeyContext` variables are declared in Step 3a (before `buildAppJson`) and are in scope here; only the `context` assembly is deferred until after `appJson` exists.

Add the two config keys to `readConfig()`'s return:

```js
    googlePlayCredentialId: optionalValue(env.APP_MANAGER_GOOGLE_PLAY_CREDENTIAL_ID),
    appleApiKeyCredentialId: optionalValue(env.APP_MANAGER_APPLE_API_KEY_CREDENTIAL_ID),
```

- [ ] **Step 4: Run the full demo test suite**

Run: `cd demos/expo-hello-world && node --test tests/prepare.test.mjs`
Expected: PASS (all tests, including the original regression which now also writes a `context.json` with both store keys null — it does not assert on it).

- [ ] **Step 5: Commit**

```bash
git add demos/expo-hello-world/scripts/prepare.mjs demos/expo-hello-world/tests/prepare.test.mjs
git commit -m "feat(demo): download store keys and emit fastlane/context.json"
```

---

## Task 7: DemoSeeder — icon + splash

**Files:**
- Modify: `apps/platform/database/seeders/DemoSeeder.php`
- Test: `apps/platform/tests/Feature/DemoSeederTest.php`

**Interfaces:**
- Produces: `app_icon` and `splash_icon` single-file media collections on the Example App, each a valid PNG named `icon.png` / `splash.png`.

- [ ] **Step 1: Write the failing assertions**

In `apps/platform/tests/Feature/DemoSeederTest.php::test_it_seeds_the_demo_account_graph`, add before the closing brace:

```php
        // App icon and splash media.
        $this->assertTrue($app->hasMedia('app_icon'));
        $this->assertTrue($app->hasMedia('splash_icon'));
        $this->assertSame('icon.png', $app->getFirstMedia('app_icon')->file_name);
        $this->assertSame('splash.png', $app->getFirstMedia('splash_icon')->file_name);
```

In `test_it_is_idempotent`, change `$this->assertDatabaseCount('media', 3);` to `$this->assertDatabaseCount('media', 5);`.
In `test_database_seeder_is_idempotent`, change `$this->assertDatabaseCount('media', 3);` to `$this->assertDatabaseCount('media', 5);`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: FAIL — no `app_icon` media; media count is 3.

- [ ] **Step 3: Seed the PNGs**

In `apps/platform/database/seeders/DemoSeeder.php`, at the end of `run()`, add:

```php
        if (! $app->hasMedia('app_icon')) {
            $app->addMediaFromString($this->makePng(1024, '#3b82f6'))
                ->usingFileName('icon.png')
                ->toMediaCollection('app_icon');
        }

        if (! $app->hasMedia('splash_icon')) {
            $app->addMediaFromString($this->makePng(2048, '#0f172a'))
                ->usingFileName('splash.png')
                ->toMediaCollection('splash_icon');
        }
```

Add this private method to the class:

```php
    private function makePng(int $size, string $hex): string
    {
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new \RuntimeException('Unable to create the demo image.');
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
```

Note: this uses the GD extension (bundled with the project's PHP — `imagepng` is already available in this Laravel install). If `php -m | grep -i gd` shows GD absent, stop and report; do not substitute a hard-coded PNG blob.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/database/seeders/DemoSeeder.php apps/platform/tests/Feature/DemoSeederTest.php
git commit -m "feat(seed): add demo app icon and splash"
```

---

## Task 8: DemoSeeder — Google Play service account + App Store API key

**Files:**
- Modify: `apps/platform/database/seeders/DemoSeeder.php`
- Test: `apps/platform/tests/Feature/DemoSeederTest.php`

**Interfaces:**
- Consumes: `makeDemoServiceAccount()` (new) and the existing openssl helpers.
- Produces: identifier-scoped `google_play_service_account` credential ("Google Play Service Account") on the Android identifier with a valid service-account JSON file; identifier-scoped `apple_api_key` credential ("App Store Connect API Key") on the iOS identifier with payload `{ key_id: 'DEMOKEY123', issuer_id: <uuid> }` and a `.p8` PEM file.

- [ ] **Step 1: Write the failing assertions**

In `DemoSeederTest::test_it_seeds_the_demo_account_graph`, add before the closing brace:

```php
        // Google Play service account, attached directly to the Android identifier.
        $googlePlay = Credential::where('name', 'Google Play Service Account')->firstOrFail();
        $this->assertSame(CredentialType::GooglePlayServiceAccount, $googlePlay->type);
        $this->assertTrue($androidIdentifier->credentials()->whereKey($googlePlay->id)->exists());
        $googlePlayJson = json_decode(file_get_contents($googlePlay->getFirstMedia('file')->getPath()), true);
        $this->assertSame('service_account', $googlePlayJson['type']);
        $this->assertArrayHasKey('private_key', $googlePlayJson);

        // App Store Connect API key, attached directly to the iOS identifier.
        $appleKey = Credential::where('name', 'App Store Connect API Key')->firstOrFail();
        $this->assertSame(CredentialType::AppleApiKey, $appleKey->type);
        $this->assertTrue($iosIdentifier->credentials()->whereKey($appleKey->id)->exists());
        $this->assertSame('DEMOKEY123', $appleKey->payload['key_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $appleKey->payload['issuer_id']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', file_get_contents($appleKey->getFirstMedia('file')->getPath()));
```

In `test_it_is_idempotent`: change `credentialables` from 3 to 5, `media` from 5 to 7, and add
`$this->assertSame(1, Credential::where('name', 'Google Play Service Account')->count());` and
`$this->assertSame(1, Credential::where('name', 'App Store Connect API Key')->count());`.
In `test_database_seeder_is_idempotent`: change `media` from 5 to 7.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: FAIL — the two credentials do not exist.

- [ ] **Step 3: Seed the two credentials**

In `DemoSeeder::run()`, after the iOS signing block, add:

```php
        // Google Play service account (store submission) on the Android identifier.
        $googlePlay = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GooglePlayServiceAccount, 'name' => 'Google Play Service Account'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$googlePlay->id]);

        if (! $googlePlay->hasMedia('file')) {
            $googlePlay->addMediaFromString($this->makeDemoServiceAccount())
                ->usingFileName('google-play-service-account.json')
                ->toMediaCollection('file');
        }

        // App Store Connect API key (TestFlight submission) on the iOS identifier.
        $appleKey = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::AppleApiKey, 'name' => 'App Store Connect API Key'],
            ['payload' => ['key_id' => 'DEMOKEY123', 'issuer_id' => '11111111-1111-4111-8111-111111111111']],
        );
        $iosIdentifier->credentials()->syncWithoutDetaching([$appleKey->id]);

        if (! $appleKey->hasMedia('file')) {
            openssl_pkey_export(openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]), $p8);
            $appleKey->addMediaFromString($p8)
                ->usingFileName('appstore-api-key.p8')
                ->toMediaCollection('file');
        }
```

Add this private method:

```php
    private function makeDemoServiceAccount(): string
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'app-manager-demo',
            'private_key_id' => 'demo-key-id',
            'private_key' => $privateKey,
            'client_email' => 'demo@app-manager-demo.iam.gserviceaccount.com',
            'client_id' => '000000000000000000000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
```

Note `DEMOKEY123` is exactly 10 uppercase alphanumeric characters, satisfying the `apple_api_key` payload rule.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/database/seeders/DemoSeeder.php apps/platform/tests/Feature/DemoSeederTest.php
git commit -m "feat(seed): add demo Google Play and App Store Connect keys"
```

---

## Task 9: DemoSeeder — client Firebase files

**Files:**
- Modify: `apps/platform/database/seeders/DemoSeeder.php`
- Test: `apps/platform/tests/Feature/DemoSeederTest.php`

**Interfaces:**
- Consumes: `CredentialType::GoogleServicesJson` / `GoogleServiceInfoPlist` (Task 1) and their validators (Task 2).
- Produces: identifier-scoped `google_services_json` credential ("Firebase google-services.json") on the Android identifier and `google_service_info_plist` credential ("Firebase GoogleService-Info.plist") on the iOS identifier, each with a file that passes the Task 2 validators.

- [ ] **Step 1: Write the failing assertions**

In `DemoSeederTest::test_it_seeds_the_demo_account_graph`, add before the closing brace:

```php
        // Client Firebase files, one per platform identifier.
        $androidFirebase = Credential::where('name', 'Firebase google-services.json')->firstOrFail();
        $this->assertSame(CredentialType::GoogleServicesJson, $androidFirebase->type);
        $this->assertTrue($androidIdentifier->credentials()->whereKey($androidFirebase->id)->exists());
        $androidFirebaseJson = json_decode(file_get_contents($androidFirebase->getFirstMedia('file')->getPath()), true);
        $this->assertNotEmpty($androidFirebaseJson['project_info']);
        $this->assertNotEmpty($androidFirebaseJson['client']);

        $iosFirebase = Credential::where('name', 'Firebase GoogleService-Info.plist')->firstOrFail();
        $this->assertSame(CredentialType::GoogleServiceInfoPlist, $iosFirebase->type);
        $this->assertTrue($iosIdentifier->credentials()->whereKey($iosFirebase->id)->exists());
        $plistContents = file_get_contents($iosFirebase->getFirstMedia('file')->getPath());
        $this->assertStringContainsString('<key>BUNDLE_ID</key>', $plistContents);
        $this->assertStringContainsString('<key>GOOGLE_APP_ID</key>', $plistContents);
```

In `test_it_is_idempotent`: change `credentialables` from 5 to 7, `media` from 7 to 9, and add
`$this->assertSame(1, Credential::where('name', 'Firebase google-services.json')->count());` and
`$this->assertSame(1, Credential::where('name', 'Firebase GoogleService-Info.plist')->count());`.
In `test_database_seeder_is_idempotent`: change `media` from 7 to 9.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: FAIL — the two Firebase credentials do not exist.

- [ ] **Step 3: Seed the client Firebase files**

In `DemoSeeder::run()`, after the Task 8 block, add:

```php
        // Client Firebase config for Android.
        $androidFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServicesJson, 'name' => 'Firebase google-services.json'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$androidFirebase->id]);

        if (! $androidFirebase->hasMedia('file')) {
            $androidFirebase->addMediaFromString($this->makeGoogleServicesJson())
                ->usingFileName('google-services.json')
                ->toMediaCollection('file');
        }

        // Client Firebase config for iOS.
        $iosFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServiceInfoPlist, 'name' => 'Firebase GoogleService-Info.plist'],
            ['payload' => null],
        );
        $iosIdentifier->credentials()->syncWithoutDetaching([$iosFirebase->id]);

        if (! $iosFirebase->hasMedia('file')) {
            $iosFirebase->addMediaFromString($this->makeGoogleServiceInfoPlist())
                ->usingFileName('GoogleService-Info.plist')
                ->toMediaCollection('file');
        }
```

Add these private methods:

```php
    private function makeGoogleServicesJson(): string
    {
        return json_encode([
            'project_info' => [
                'project_number' => '000000000000',
                'project_id' => 'app-manager-demo',
                'storage_bucket' => 'app-manager-demo.appspot.com',
            ],
            'client' => [[
                'client_info' => [
                    'mobilesdk_app_id' => '1:000000000000:android:0000000000000000',
                    'android_client_info' => ['package_name' => self::IOS_BUNDLE_IDENTIFIER],
                ],
                'api_key' => [['current_key' => 'DEMO-ANDROID-API-KEY']],
            ]],
            'configuration_version' => '1',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function makeGoogleServiceInfoPlist(): string
    {
        $bundleId = self::IOS_BUNDLE_IDENTIFIER;

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>BUNDLE_ID</key><string>{$bundleId}</string>
            <key>PROJECT_ID</key><string>app-manager-demo</string>
            <key>GOOGLE_APP_ID</key><string>1:000000000000:ios:0000000000000000</string>
            <key>API_KEY</key><string>DEMO-IOS-API-KEY</string>
            <key>GCM_SENDER_ID</key><string>000000000000</string>
            <key>IS_ADS_ENABLED</key><false/>
        </dict>
        </plist>
        PLIST;
    }
```

Note the heredoc closing `PLIST;` must be at column 0 or use an indented closing marker consistent with your PHP version (8.3 supports flexible heredoc — the closing marker's indentation is stripped from each line). Verify the produced string starts with `<?xml`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/platform && php artisan test --filter DemoSeederTest`
Expected: PASS.

- [ ] **Step 5: Run the full API suite to catch cross-test count drift**

Run: `cd apps/platform && php artisan test`
Expected: PASS (all). If a media/credentialables count assertion elsewhere fails, update it to match the new seed graph.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/database/seeders/DemoSeeder.php apps/platform/tests/Feature/DemoSeederTest.php
git commit -m "feat(seed): add demo client Firebase google-services files"
```

---

## Task 10: Fastfile — build and deploy lanes

**Files:**
- Create: `demos/expo-hello-world/Gemfile`
- Create: `demos/expo-hello-world/fastlane/Appfile`
- Create: `demos/expo-hello-world/fastlane/Fastfile`

**Interfaces:**
- Consumes: `fastlane/context.json`, `credentials.json`, `app.json` produced by `prepare` (Tasks 4–6), and the `android/` + `ios/` projects from `expo prebuild`.
- Produces: lanes `android build` / `android deploy`, `ios build` / `ios deploy`, and cross-platform `build` / `deploy` (option `platforms:"ios,android"`). Deploy submits only when the store credential is present in `context.json` AND (`submit:true` or `APP_MANAGER_ALLOW_DEPLOY=1`).

- [ ] **Step 1: Create the Gemfile**

`demos/expo-hello-world/Gemfile`:

```ruby
source "https://rubygems.org"

gem "fastlane"
```

- [ ] **Step 2: Create the Appfile**

`demos/expo-hello-world/fastlane/Appfile` — reads identifiers from `context.json` so nothing is hard-coded:

```ruby
require "json"

context_path = File.expand_path("context.json", __dir__)
context = File.exist?(context_path) ? JSON.parse(File.read(context_path)) : {}

app_identifier(context.dig("ios", "bundleIdentifier"))
package_name(context.dig("android", "package"))
```

- [ ] **Step 3: Create the Fastfile**

Flat lanes (no `platform` blocks) so `build`/`deploy` can invoke the per-platform
lanes as plain method calls — the idiomatic, working cross-lane pattern.

`demos/expo-hello-world/fastlane/Fastfile`:

```ruby
require "json"

CONTEXT_PATH = File.expand_path("context.json", __dir__)
CREDENTIALS_PATH = File.expand_path("../credentials.json", __dir__)
PROJECT_ROOT = File.expand_path("..", __dir__)

def load_context
  UI.user_error!("fastlane/context.json is missing. Run `npm run prepare` first.") unless File.exist?(CONTEXT_PATH)
  JSON.parse(File.read(CONTEXT_PATH))
end

def load_credentials
  UI.user_error!("credentials.json is missing. Run `npm run prepare` first.") unless File.exist?(CREDENTIALS_PATH)
  JSON.parse(File.read(CREDENTIALS_PATH))
end

def deploy_allowed?(options)
  options[:submit] == true || ENV["APP_MANAGER_ALLOW_DEPLOY"] == "1"
end

before_all do
  unless Dir.exist?(File.join(PROJECT_ROOT, "android")) && Dir.exist?(File.join(PROJECT_ROOT, "ios"))
    UI.message("Native projects missing; running `expo prebuild`.")
    Dir.chdir(PROJECT_ROOT) { sh("npx", "expo", "prebuild") }
  end
end

desc "Build a signed Android release AAB"
lane :build_android do
  context = load_context
  keystore = load_credentials.dig("android", "keystore")
  UI.user_error!("No Android keystore in credentials.json") unless keystore

  gradle(
    project_dir: File.join(PROJECT_ROOT, "android"),
    task: "bundle",
    build_type: "Release",
    properties: {
      "android.injected.signing.store.file" => File.expand_path(keystore["keystorePath"], PROJECT_ROOT),
      "android.injected.signing.store.password" => keystore["keystorePassword"],
      "android.injected.signing.key.alias" => keystore["keyAlias"],
      "android.injected.signing.key.password" => keystore["keyPassword"],
    },
  )
  UI.success("Built #{context.dig('android', 'package')} release bundle.")
end

desc "Build and (optionally) upload Android to Google Play internal track"
lane :deploy_android do |options|
  build_android
  context = load_context
  json_key = context.dig("android", "googlePlayJsonKeyPath")

  if json_key.nil?
    UI.important("No Google Play service account in context.json; stopping at the signed artifact.")
    next
  end
  unless deploy_allowed?(options)
    UI.important("Deploy not requested (pass submit:true or set APP_MANAGER_ALLOW_DEPLOY=1); stopping at the signed artifact.")
    next
  end

  upload_to_play_store(
    package_name: context.dig("android", "package"),
    json_key: File.expand_path(json_key, PROJECT_ROOT),
    track: "internal",
    aab: lane_context[SharedValues::GRADLE_AAB_OUTPUT_PATH],
  )
end

desc "Build a signed iOS release IPA"
lane :build_ios do
  context = load_context
  workspace = Dir[File.join(PROJECT_ROOT, "ios", "*.xcworkspace")].first
  UI.user_error!("No Xcode workspace found; run `expo prebuild` first.") if workspace.nil?

  build_app(
    workspace: workspace,
    scheme: File.basename(workspace, ".xcworkspace"),
    export_method: "app-store",
    output_directory: File.join(PROJECT_ROOT, "build"),
  )
  UI.success("Built #{context.dig('ios', 'bundleIdentifier')} release IPA.")
end

desc "Build and (optionally) upload iOS to TestFlight"
lane :deploy_ios do |options|
  build_ios
  context = load_context
  api_key = context.dig("ios", "appStoreApiKey")

  if api_key.nil?
    UI.important("No App Store Connect API key in context.json; stopping at the signed artifact.")
    next
  end
  unless deploy_allowed?(options)
    UI.important("Deploy not requested (pass submit:true or set APP_MANAGER_ALLOW_DEPLOY=1); stopping at the signed artifact.")
    next
  end

  key = app_store_connect_api_key(
    key_id: api_key["keyId"],
    issuer_id: api_key["issuerId"],
    key_filepath: File.expand_path(api_key["path"], PROJECT_ROOT),
  )
  upload_to_testflight(api_key: key)
end

desc "Build one or more platforms (platforms:\"ios,android\")"
lane :build do |options|
  platforms = (options[:platforms] || "ios,android").split(",").map(&:strip)
  build_android if platforms.include?("android")
  build_ios if platforms.include?("ios")
end

desc "Deploy one or more platforms (platforms:\"ios,android\" submit:true)"
lane :deploy do |options|
  platforms = (options[:platforms] || "ios,android").split(",").map(&:strip)
  deploy_android(options) if platforms.include?("android")
  deploy_ios(options) if platforms.include?("ios")
end
```

The invocation commands change accordingly: `bundle exec fastlane build_android`,
`bundle exec fastlane deploy_ios submit:true`, or the dispatchers
`bundle exec fastlane build platforms:"ios,android"`.

- [ ] **Step 4: Verify Ruby syntax (automated gate)**

Run: `cd demos/expo-hello-world && ruby -c fastlane/Fastfile && ruby -c fastlane/Appfile`
Expected: `Syntax OK` for both. (`ruby` ships with macOS; this checks the lanes parse without needing fastlane installed. Actual builds require Xcode/Android SDK and are manual.)

- [ ] **Step 5: Commit**

```bash
git add demos/expo-hello-world/Gemfile demos/expo-hello-world/fastlane/Appfile demos/expo-hello-world/fastlane/Fastfile
git commit -m "feat(demo): add Fastlane build and deploy lanes"
```

---

## Task 11: Docs + .gitignore

**Files:**
- Modify: `demos/expo-hello-world/.gitignore`
- Modify: `demos/expo-hello-world/README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the new downloaded files ignored by git; README documents the new prepare outputs and Fastlane usage.

- [ ] **Step 1: Update .gitignore**

Append to `demos/expo-hello-world/.gitignore`:

```gitignore

# Generated by `npm run prepare`; downloaded assets and Firebase client config.
assets/icon.*
assets/splash.*
google-services.json
GoogleService-Info.plist
fastlane/context.json

# Fastlane build output and local state.
build/
fastlane/report.xml
fastlane/Preview.html
vendor/bundle/
.bundle/
```

- [ ] **Step 2: Update README**

In `demos/expo-hello-world/README.md`, extend the "Что создаёт prepare" list with the new outputs and add a "Сборка и деплой (Fastlane)" section. Add after the existing bullet list:

```markdown
Дополнительно prepare скачивает (если настроены в Platform):

- `assets/icon.png` и `assets/splash.png` — иконка и splash, прописываются в `app.json`;
- `google-services.json` / `GoogleService-Info.plist` — клиентский Firebase config,
  прописывается в `expo.android.googleServicesFile` / `expo.ios.googleServicesFile`;
- `credentials/android/google-play-service-account.json` — ключ Google Play для загрузки;
- `credentials/ios/appstore-api-key.p8` — App Store Connect API key для TestFlight;
- `fastlane/context.json` — машинный манифест для Fastlane (идентификаторы, версии,
  типы профилей, пути к store-ключам). Пароли остаются только в `credentials.json`.

## Сборка и деплой (Fastlane)

Требуется Ruby + Fastlane (`bundle install`), а также Xcode (iOS) и Android SDK.

```bash
bundle install
npm run prepare          # готовит app.json, credentials.json, context.json, native-проекты

bundle exec fastlane build platforms:"android"      # signed AAB
bundle exec fastlane build platforms:"ios"          # signed IPA
bundle exec fastlane build                          # обе платформы

# Деплой опционален: по умолчанию lane останавливается на подписанном артефакте.
bundle exec fastlane deploy platforms:"android" submit:true   # → Google Play (internal)
bundle exec fastlane deploy platforms:"ios" submit:true       # → TestFlight
```

Демо-credentials самоподписанные — реальные сторы их отклонят. Деплой предназначен
для использования с настоящими store-ключами, загруженными в Platform.
```

- [ ] **Step 3: Verify the demo test suite still passes**

Run: `cd demos/expo-hello-world && npm test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add demos/expo-hello-world/.gitignore demos/expo-hello-world/README.md
git commit -m "docs(demo): document new prepare outputs and Fastlane usage"
```

---

## Final Verification

- [ ] Run the full API suite: `cd apps/platform && php artisan test` — expect all green.
- [ ] Run the demo suite: `cd demos/expo-hello-world && node --test tests/*.test.mjs` — expect all green.
- [ ] Syntax-check Fastlane: `cd demos/expo-hello-world && ruby -c fastlane/Fastfile && ruby -c fastlane/Appfile`.
- [ ] Manual end-to-end (optional, needs running Platform + native toolchain): `php artisan migrate:fresh --seed` in `apps/platform`, then in `demos/expo-hello-world` run `npm install --ignore-scripts && npm run prepare` and confirm `app.json` has `icon`, `splash`, `googleServicesFile`, and that `fastlane/context.json`, `google-services.json`, `GoogleService-Info.plist`, `credentials/android/google-play-service-account.json`, `credentials/ios/appstore-api-key.p8` all exist.
