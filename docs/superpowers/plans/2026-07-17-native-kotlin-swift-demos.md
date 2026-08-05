# Native Kotlin and Swift demo implementation plan

**Date:** 2026-07-17
**Milestone:** 4 of the multi-platform demos roadmap
**Status:** Implemented and regression-tested

## Outcome

Two independent native application source trees consume the same App Manager
build-context contract through `fastlane-plugin-app_manager`:

- `demos/kotlin-hello-world` builds an Android application written in Kotlin;
- `demos/swift-hello-world` builds an iOS application written in Swift.

Both expose `prepare`, `build`, `deploy` and `clean`, support both seeded brands,
and generate all brand-specific data outside tracked source.

## Shared rules

- Fastlane plugin is the only HTTP/API client.
- Each demo owns a small local adapter; adapters accept resolved JSON and never
  authenticate, select targets or download URLs.
- Generated signing material and configuration live under `.app-manager/` and
  are ignored. Firebase client files are also ignored.
- Required version/build/signing fields fail during prepare, before compilation.
- `APP_MANAGER_APP` selects a white-label brand; `APP_MANAGER_IDENTIFIER`
  optionally selects a non-default target.
- Adapter tests run without a Platform server and compare `example-app` with
  `example-app-blue`.

## Kotlin demo

The Android build keeps a stable Kotlin namespace but receives the distributable
`applicationId`, app name, version name and version code from generated Gradle
properties. A generated resource directory supplies App Manager icon/splash
assets without editing `src/main`.

Signing properties point to the plugin-downloaded keystore. The Google Services
Gradle plugin is applied only when prepare downloaded `google-services.json`.
Fastlane uses the same generated context for Play Store deployment.

Acceptance checks:

- Android-only build context and production/preview profiles work;
- default and explicit beta identifiers work;
- two brands generate different IDs, versions, assets, Firebase, keystore and
  Google Play paths;
- secret JSON/properties use mode `0600`;
- Gradle project configuration can be evaluated locally.

## Swift demo

The Xcode project uses an optional generated `.xcconfig` for product bundle ID,
display name, marketing version and build number. The generated asset catalogue
contains the selected icon and splash image. A build phase copies the optional
Firebase plist into the app bundle.

Fastlane installs the selected certificate and provisioning profile in a
temporary keychain and maps App Manager profiles to Xcode export methods.
App Store Connect deployment remains opt-in.

Acceptance checks:

- iOS build context drives every identifier/version setting;
- two brands generate different xcconfig, assets, Firebase, signing and store
  paths;
- tracked project/source files remain unchanged after prepare;
- adapter tests pass and Xcode can parse the project/build settings.

## Verification

- Laravel API suite;
- plugin RSpec suite;
- Expo Node suite;
- Kotlin adapter tests and Gradle configuration;
- Swift adapter tests, Ruby syntax and Xcode project/build-setting checks;
- `git diff --check` and ignored-secret checks.
