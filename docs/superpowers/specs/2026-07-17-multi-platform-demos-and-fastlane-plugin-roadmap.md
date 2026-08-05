# Multi-platform demos and Fastlane plugin roadmap

**Date:** 2026-07-17
**Status:** Milestones 1–4 implemented; Milestone 5 planned
**Scope:** `apps/platform`, `libs/fastlane-plugin`, `demos`

## Goal

App Manager must provide one stable build contract that can be consumed from:

- Expo;
- a native Android application written in Kotlin;
- a native iOS application written in Swift.

Every example must support both product shapes:

1. independent applications with different codebases;
2. white-label applications built from the same source tree with different
   names, identifiers, assets, Firebase files, signing credentials and store
   credentials.

The Fastlane plugin is the only API integration layer used by demos. A demo may
contain a framework-specific adapter, but it must not implement its own HTTP
client, credential matching rules or App Manager authentication.

## Implementation progress

- Milestone 1 is complete: deterministic build-context API, identifier build
  fields, shared contract fixture and seeded white-label/multi-target data.
- Milestone 2 is complete: plugin build-context/Firebase actions, request cache,
  expiration checks and Fastlane plugin-manager integration.
- Milestone 3 is complete: Expo uses plugin-only, platform-specific workflows,
  generates ignored configuration and tests both seeded brands on Android/iOS.
- Milestone 4 is complete: independent Kotlin/Android and Swift/iOS source
  trees use generated Gradle/Xcode configuration and the same plugin contract.
- The reference-demo set is complete. Bare React Native and Flutter demos were
  removed from the required scope on 2026-07-18; the next milestone is CI and
  operational end-to-end verification of Expo, Kotlin and Swift.

## Domain model

The project uses the following product semantics:

- `MobileApp` is one distributable application/brand.
- `AppIdentifier` is a platform target of that application, for example the
  Android production package or the iOS beta bundle identifier.
- A build profile is a distribution target attached to an identifier, for
  example Android `production` or iOS `appstore`.
- A credential is owned by the team and attached either directly to an
  identifier or to one or more build profiles.
- A white-label codebase builds multiple `MobileApp` records. Selecting a brand
  means selecting `APP_MANAGER_APP`; it does not mean selecting a second
  identifier inside the same `MobileApp`.
- Multiple identifiers inside one `MobileApp` represent platforms or channels
  of the same brand, not separate brands.

This choice matches the current storage model: name, description, icon and
splash belong to `MobileApp`, so they can vary between white-label brands
without adding a new `Variant` entity.

## Baseline at planning time

### Platform API

The API already exposes:

- app name, slug, description, icon and splash;
- iOS and Android identifiers;
- shared semantic version and Android `version_code`;
- profile-scoped signing credentials;
- identifier-scoped Firebase, push and store credentials;
- encrypted credential payloads and authenticated file downloads;
- the credential type/schema catalogue.

The current resources expose all persisted fields used by the existing Expo
demo, but they do not yet form a complete or deterministic build contract.

### Fastlane plugin

The plugin already provides typed actions for app info, assets, iOS signing,
Android signing, APNs, App Store Connect, Google Play and the server-side
Firebase HTTP v1 service account.

Missing plugin coverage:

- Android client `google-services.json`;
- iOS client `GoogleService-Info.plist`;
- one build-context action that resolves a platform target consistently;
- explicit handling of expired credentials;
- request caching within one Fastlane process;
- a contract fixture shared with the Laravel API tests.

### Expo demo

The existing Expo demo proves most of the end-to-end data flow, but its
`prepare.mjs` is also a second App Manager client. It fetches three endpoints,
selects identifiers and credentials, downloads files and interprets payloads
independently from the Fastlane plugin.

It also prepares Android and iOS together, so an Android-only run cannot succeed
without iOS data. Its default identifiers are tied to `com.example.app`, and
prepare overwrites the tracked `app.json`.

## Required API contract

### Missing target fields

Add to `AppIdentifier`:

| Field | Type | Meaning |
|---|---|---|
| `build_number` | nullable string | iOS `CFBundleVersion` / Expo `ios.buildNumber` |
| `is_default` | boolean | Explicit default identifier for this app and platform |

`version_code` remains Android-only. `build_number` is iOS-only. `version`
remains the user-facing version shared by all frameworks.

Only one identifier may be default for an app/platform pair. The first
identifier created for a platform becomes default. A consumer may omit the
identifier only when there is an explicit default or exactly one compatible
identifier; otherwise the API returns a validation error instead of selecting
by sort order.

### Build-context endpoint

Introduce a versioned read endpoint:

```text
GET /api/v1/{team_slug}/apps/{app_slug}/build-context
    ?platform=android|ios
    &profile_type=production|preview|development|appstore|adhoc|enterprise
    &identifier=com.example.app
```

`platform` and `profile_type` are required. `identifier` is optional and follows
the deterministic default rules above.

The response is a consistent snapshot containing:

```json
{
  "data": {
    "schema_version": 1,
    "app": {},
    "target": {},
    "profile": {},
    "assets": {},
    "credentials": []
  }
}
```

- `app`: id, slug, name and description.
- `target`: identifier id/name/platform/value, version, version code, build
  number and default flag.
- `profile`: profile type plus the label, artifact and description from
  `BuildProfileSchema`; the database profile id may be null when no credential
  has created the relation yet.
- `assets`: authenticated metadata/download URLs for the app icon and splash.
- `credentials`: every credential compatible with the selected identifier and
  profile, using the existing `CredentialResource` shape.

The endpoint does not silently choose between two credentials of the same
type. It returns compatible candidates; the plugin applies an explicit
credential id/name selector and fails on ambiguity.

The endpoint must have the same team scoping and secret-read authorization as
the existing credential endpoints. More granular Sanctum token abilities are a
security milestone before production rollout; the first build-context change
must not accidentally broaden current access.

### Asset contract

The first milestone keeps the existing icon and splash collections. While
implementing native adapters, add only platform asset fields that are actually
required by at least one demo. Expected candidates are:

- Android adaptive-icon foreground;
- adaptive-icon background color;
- splash background color and resize mode.

Do not add a schema-free settings JSON as a shortcut. New build fields should
have validation, documentation and at least one real consumer.

## Fastlane plugin architecture

The plugin owns:

- API authentication and team/app scoping;
- build-context retrieval and validation;
- identifier/profile/credential selection;
- authenticated downloads and secure file modes;
- consistent error messages;
- rejection of missing, ambiguous or expired required credentials.

Framework demos own:

- writing Expo config, Gradle properties, `.xcconfig`, resource files and asset
  catalogues;
- invoking the framework/native build command;
- installing signing material using supported Fastlane actions;
- optional store deployment.

Typed download actions remain composable. `app_manager_build_context` is the
shared discovery action; it does not force every project into one directory
layout.

## Demo matrix

| Demo | Android adapter | iOS adapter | Build output |
|---|---|---|---|
| Expo | generated Expo config + prebuild | generated Expo config + prebuild | AAB / IPA |
| Kotlin | Gradle properties, resources, signing config | n/a | APK / AAB |
| Swift | n/a | generated `.xcconfig`, plist and asset catalogue | IPA |

Every demo exposes the same workflow names:

```text
prepare
build
deploy
clean
```

Every demo accepts the same primary inputs:

```text
APP_MANAGER_BASE_URL
APP_MANAGER_API_KEY
APP_MANAGER_TEAM
APP_MANAGER_APP
APP_MANAGER_PLATFORM
APP_MANAGER_IDENTIFIER
APP_MANAGER_PROFILE_TYPE
```

Credential-specific ids remain optional disambiguation inputs.

## Required scenarios

### Independent codebases

Each demo is an independent source tree and can build one seeded application
through the same plugin/API contract. No demo imports another demo's adapter.
Shared test utilities are allowed, but every demo must remain understandable
and extractable on its own.

### White-label

At least two seeded applications, `example-app` and `example-app-blue`, are
built from an unchanged demo source tree. Tests must prove that the generated
configuration differs in:

- application name;
- Android package and iOS bundle identifier;
- icon/splash;
- client Firebase files;
- signing credentials;
- store credentials.

### Multiple targets and ambiguous credentials

At least one seeded app has production and beta identifiers. Tests must prove
that:

- explicit identifier selection works;
- omission uses the declared default;
- two matching credentials require credential id/name selection;
- a credential attached to another app or target is never returned.

## Delivery milestones

### Milestone 1 — API build contract (complete)

- Add iOS build number and explicit default identifier.
- Add build-context endpoint and exact contract tests.
- Expand `DemoSeeder` to two brands and multiple targets.
- Update API documentation.

Detailed plan: `docs/superpowers/plans/2026-07-17-api-build-context.md`.

### Milestone 2 — Fastlane plugin parity (complete)

- Add build-context client/action.
- Add both client Firebase download actions.
- Add selection, caching and expiration behavior.
- Add Laravel contract fixture tests.
- Document platform-only and white-label lanes.

### Milestone 3 — Expo reference implementation (complete)

- Route all App Manager access through the plugin.
- Support platform-only prepare/build.
- Stop modifying tracked configuration.
- Verify both seeded white-label brands.

### Milestone 4 — Native foundations (complete)

- Add Kotlin demo.
- Add Swift demo.
- Establish reusable Android resource and iOS asset-catalogue generation
  patterns.

### Milestone 5 — CI and operational hardening

- Run API, plugin and adapter unit tests on every change.
- Run prepare for every demo × seeded brand.
- Build Android artifacts in CI.
- Run iOS configuration/simulator checks on pull requests.
- Run real signed iOS end-to-end builds only on a protected macOS environment
  with valid Apple credentials.
- Assert that prepare/build leaves the repository clean and no credential file
  is tracked.

## Definition of done

- All demos use the Fastlane plugin as their only App Manager client.
- Android and iOS can be prepared independently.
- The same source commit builds two white-label applications.
- All platform identifiers, versions and build numbers come from App Manager.
- Client Firebase, signing and store credentials are scoped to the selected
  target/profile.
- Ambiguity and expiration fail before a build starts.
- Generated secrets use mode `0600` and are ignored by Git.
- API resources and plugin fixtures are covered by exact contract tests.
- Documentation includes one independent-app and one white-label CI example.

## Non-goals

- Managing Apple/Google credentials remotely through `match`, `sigh` or an
  equivalent external credential store.
- Replacing Fastlane with a new general-purpose App Manager CLI.
- Adding a generic variant model while the one-`MobileApp`-per-brand model is
  sufficient.
- Adding framework-specific configuration fields before a demo consumes them.
- Maintaining reference demos for bare React Native or Flutter. These
  frameworks may consume the generic Fastlane actions, but they are not part of
  the supported demo and CI matrix.
