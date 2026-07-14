# Expo demo pipeline: complete prepare + Fastlane build/deploy

**Date:** 2026-07-14
**Status:** Approved design
**Scope:** `demos/expo-hello-world` (prepare script, Fastfile) and `apps/platform` (API credential types, DemoSeeder)

## Problem

The `demos/expo-hello-world` `prepare` script fetches app data and signing
credentials from the App Manager API and writes `app.json` + `credentials.json`.
It has three gaps:

1. It never downloads or wires the app **icon** and **splash screen**, even
   though the API exposes them under `/apps/{slug}/assets/{asset}`.
2. It never finds **identifier-scoped** credentials. Its only selector,
   `selectProfileCredential`, matches on `credential.profiles[]`, so credentials
   attached directly to an app identifier — `firebase_config`,
   `google_play_service_account`, `apple_api_key`, and the new google-services
   types — are structurally invisible to it.
3. There is no build/deploy automation. The demo cannot produce a signed
   artifact or submit to a store.

The goal: `prepare` fully provisions the project (assets, client Firebase files,
store-submission keys), and a new `Fastfile` builds one or more platforms and
optionally deploys to the stores, all without EAS.

## Data flow

```
API (app + assets + credentials)
  -> prepare.mjs
       app.json + credentials.json + fastlane/context.json + files on disk
  -> expo prebuild (android/, ios/)
  -> Fastfile: build_* (gym/gradle) -> deploy_* (pilot/supply, optional)
```

## Component 1 — API: client Firebase files

The API's existing `firebase_config` type is the **server-side FCM HTTP v1
service account** (`project_id`/`client_email`/`private_key`). It is not the
**client** config files that Expo bundles into the native build via
`googleServicesFile`. Those have no home in the API today.

Add two identifier-scoped, file-only credential types (no payload fields),
following the `google_play_service_account` pattern:

| Type | Platform | Extension | File |
|---|---|---|---|
| `google_services_json` | android | `.json` | `google-services.json` |
| `google_service_info_plist` | ios | `.plist` | `GoogleService-Info.plist` |

Changes:

- `App\Enums\CredentialType`: add `GoogleServicesJson = 'google_services_json'`
  and `GoogleServiceInfoPlist = 'google_service_info_plist'`.
- `App\Support\Credentials\CredentialSchema::definition()`: add both via
  `make()` with `attachment: identifier`, no fields, correct platforms and
  extensions, descriptive file hint.
- `App\Rules\ValidCredentialFile`:
  - `google_services_json`: parseable JSON containing a non-empty `project_info`
    object and a `client` array. Structural check only — no private key.
  - `google_service_info_plist`: parseable as a plist (well-formed XML plist)
    that contains the expected keys (at least `BUNDLE_ID` and one of
    `GOOGLE_APP_ID` / `PROJECT_ID`). Structural check only.

No migrations: `Credential` already attaches polymorphically to app
identifiers, and the new types flow into the web UI automatically through
`CredentialTypeController`.

**Rejected alternative:** model the files as `MobileApp` media collections like
icon/splash. Assets are validated as images and are not platform-scoped;
credential-per-identifier derives the platform from the identifier and reuses
the existing download endpoint and selection logic.

## Component 2 — prepare.mjs

All new outputs are **optional**: absent data is skipped silently, matching the
current behavior for optional fields. The existing required credentials
(keystore, iOS certificate, provisioning profile) still error when missing or
ambiguous.

1. **Icon / splash.** Read `app.assets.icon` and `app.assets.splashscreen` from
   the app response. When present, download the `url` to `assets/icon.png` /
   `assets/splash.png` (extension derived from `mime_type`) and set `expo.icon`
   and `expo.splash` (`{ image, resizeMode: 'contain', backgroundColor }`).
2. **Client Firebase.** Add a `selectIdentifierCredential()` selector that
   matches on `credential.app_identifiers[]` (parallel to the existing
   profile-scoped selector). Use it to find `google_services_json` (android) and
   `google_service_info_plist` (ios); download to the project root and set
   `expo.android.googleServicesFile` / `expo.ios.googleServicesFile`.
3. **Store keys.** With the same selector, find `google_play_service_account`
   (android) -> `credentials/android/google-play-service-account.json`, and
   `apple_api_key` (ios) -> `credentials/ios/appstore-api-key.p8`, reading
   `key_id` and `issuer_id` from the payload.
4. **`fastlane/context.json`** (mode `0600`): a machine-readable manifest for the
   Fastfile — `android` (package, versionCode, profileType, googlePlayJsonKeyPath
   or null), `ios` (bundleIdentifier, profileType, appStoreApiKey {path, keyId,
   issuerId} or null), and `version`. Keystore and certificate passwords stay
   only in `credentials.json`; the manifest never duplicates secrets.

The selectors keep their strictness: 0 matches for an optional credential = skip;
2+ matches without an explicit `APP_MANAGER_*_ID` selector = error, as today.

## Component 3 — Fastfile

Location: `demos/expo-hello-world/fastlane/` (`Fastfile`, `Appfile`). Standard
Fastlane over the native projects produced by `expo prebuild`; reads
`context.json`, `credentials.json`, and `app.json`.

- `before_all`: ensure `android/` and `ios/` exist; run `expo prebuild` if not.
- **Android**: `build_android` runs gradle `bundleRelease` signed with the
  keystore from `credentials.json`; `deploy_android` runs `supply`
  (upload_to_play_store, internal track) with the Google Play service account.
- **iOS**: `build_ios` runs `gym`/`build_app` with the provisioning profile and
  p12; `deploy_ios` runs `pilot` (upload_to_testflight) with the App Store
  Connect API key.
- **Orchestration**: `build` / `deploy` accept a `platforms:` option
  (`ios`, `android`, or both); `build_all` / `deploy_all` convenience lanes.
- **Deploy is opt-in.** A deploy lane submits only when (a) the relevant store
  credential is present in `context.json` and (b) it is explicitly requested
  (`submit:true` lane option or `APP_MANAGER_ALLOW_DEPLOY=1`). Otherwise it stops
  at the signed artifact with a clear message. Demo (self-signed) data never
  submits by design.

Requires Xcode / Android SDK on the machine — documented in the README.

## Component 4 — DemoSeeder

Idempotent additions (matching the existing `updateOrCreate` + media-presence
style), so the new prepare/Fastfile paths are exercisable locally:

- **Icon + splash**: generate valid PNGs into the `app_icon` / `splash_icon`
  collections on the Example App.
- **`google_play_service_account`** (android identifier): file is a
  service-account JSON with a real openssl-generated private key so it passes
  `validateServiceAccount`.
- **`apple_api_key`** (ios identifier): payload `{ key_id (10 chars, [A-Z0-9]),
  issuer_id (uuid) }`, file is a `.p8` PEM private key.
- **`google_services_json`** (android) + **`google_service_info_plist`** (ios):
  generate valid `google-services.json` and `GoogleService-Info.plist`.

## Component 5 — Tests + docs

- **API**: feature tests for both new credential types — successful
  store/download, and validation rejection of malformed files.
- **prepare**: extend `tests/*.test.mjs` with a mock fetch returning assets and
  identifier-scoped credentials; assert files written, `app.json` wiring,
  `context.json` contents, and graceful skip when optional data is absent.
- **DemoSeeder**: extend `DemoSeederTest` to assert the new media and credentials
  are seeded and valid.
- **Fastfile**: automated coverage limited to a parse/smoke check
  (`fastlane lanes`); build/deploy is manual because it needs the native
  toolchain. This limitation is stated explicitly.
- **README + `.gitignore`**: document the new prepare outputs and Fastlane usage;
  ignore the new downloaded files.

## Phases

Ordered for the implementation plan (later phases depend on earlier):

1. API extension (credential types + validation + tests).
2. `prepare.mjs` (identifier selector, assets, firebase, store keys, context.json).
3. DemoSeeder (demo data for everything above).
4. Fastfile (build/deploy lanes).
5. README, `.gitignore`, final verification.

## New file / directory layout (demo)

```
demos/expo-hello-world/
  assets/icon.png                                   (downloaded, gitignored)
  assets/splash.png                                 (downloaded, gitignored)
  google-services.json                              (downloaded, gitignored)
  GoogleService-Info.plist                          (downloaded, gitignored)
  credentials/android/production.keystore
  credentials/android/google-play-service-account.json   (new, gitignored)
  credentials/ios/distribution.p12
  credentials/ios/profile.mobileprovision
  credentials/ios/appstore-api-key.p8               (new, gitignored)
  fastlane/Fastfile                                 (new)
  fastlane/Appfile                                  (new)
  fastlane/context.json                             (new, gitignored, 0600)
  app.json                                          (generated)
  credentials.json                                  (generated)
```
