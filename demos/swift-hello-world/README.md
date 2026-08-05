# Native Swift Hello World

Minimal SwiftUI iOS application whose brand-specific Xcode settings and signing
material come from App Manager through `fastlane-plugin-app_manager`.

## Setup and workflows

Start and seed Platform, then install Ruby dependencies:

```bash
cd demos/swift-hello-world
bundle install

bundle exec fastlane prepare
bundle exec fastlane build
bundle exec fastlane deploy submit:true
bundle exec fastlane clean
```

The demo supports only `APP_MANAGER_PLATFORM=ios` and defaults to `appstore`.
Common inputs are:

```text
APP_MANAGER_BASE_URL=http://localhost:8000
APP_MANAGER_API_KEY=demo-api-key
APP_MANAGER_TEAM=demo-users-team
APP_MANAGER_APP=example-app
APP_MANAGER_PLATFORM=ios
APP_MANAGER_IDENTIFIER=com.example.app
APP_MANAGER_PROFILE_TYPE=appstore
```

## Generated Xcode contract

`prepare` writes ignored files under `.app-manager/`:

- `AppManager.xcconfig` overrides bundle ID, display name, marketing version and
  build number;
- `GeneratedAssets.xcassets` contains the App Manager icon and splash;
- `GoogleService-Info.plist` is copied into the app bundle by an Xcode phase;
- `credentials.json` and downloaded `.p12`/`.mobileprovision` files drive manual
  signing;
- `context.json` and the App Store Connect key drive optional TestFlight upload.

Tracked `Config/AppManager.xcconfig` supplies harmless fallbacks and optionally
includes the generated overrides. Swift source and the Xcode project are never
rewritten.

## White-label

The same Xcode project builds both seeded applications:

```bash
APP_MANAGER_APP=example-app bundle exec fastlane build
APP_MANAGER_APP=example-app-blue bundle exec fastlane build
```

Each run resolves a different name, bundle ID, build number, assets, Firebase
plist, certificate/profile and App Store key. Secret files use mode `0600`.

DemoSeeder's Apple files are structurally valid local fixtures but not trusted
for App Store distribution. Replace them with real Apple Developer credentials
before running a signed archive or deployment.
