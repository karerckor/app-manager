# Expo Hello World

Reference Expo SDK 57 application for the App Manager build contract. All API
access, target selection and credential downloads go through the local
`fastlane-plugin-app_manager`; the Node adapter only writes Expo files from the
already resolved plugin output.

The demo prepares one platform at a time. Android therefore does not require
Apple credentials, and iOS does not require an Android keystore.

## Local setup

Start Platform and seed the two demo brands from the repository root:

```bash
docker compose up -d
cd apps/platform
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Install the demo dependencies once:

```bash
cd demos/expo-hello-world
npm install
bundle install
```

The `Gemfile` loads the plugin directly from `../../libs/fastlane-plugin`, so
the demo always exercises the current repository version.

## Workflows

The four public workflows are Fastlane lanes:

```bash
# Prepare and generate only the requested native project.
bundle exec fastlane prepare platform:android
bundle exec fastlane prepare platform:ios

# Build a signed AAB or IPA. Build runs prepare when the selected platform is
# not currently prepared.
bundle exec fastlane build platform:android
bundle exec fastlane build platform:ios

# Deployment is opt-in; without submit:true this stops at the signed artifact.
bundle exec fastlane deploy platform:android submit:true
bundle exec fastlane deploy platform:ios submit:true

# Remove native projects, downloaded secrets, generated config and artifacts.
bundle exec fastlane clean
```

`npm start`, `npm run android` and `npm run ios` remain available for local Expo
development after the corresponding `prepare` lane.

## Inputs

Every run accepts the common demo contract:

```text
APP_MANAGER_BASE_URL=http://localhost:8000
APP_MANAGER_API_KEY=demo-api-key
APP_MANAGER_TEAM=demo-users-team
APP_MANAGER_APP=example-app
APP_MANAGER_PLATFORM=android
APP_MANAGER_IDENTIFIER=com.example.app
APP_MANAGER_PROFILE_TYPE=production
```

`platform:` and `profile_type:` lane options override the environment. Android
defaults to the `production` profile and iOS to `appstore`. The identifier may
be omitted: the API then selects the explicit default, or the only compatible
target. It fails when selection is ambiguous.

Credential IDs are optional disambiguation inputs:

```text
APP_MANAGER_ANDROID_CREDENTIAL_ID
APP_MANAGER_ANDROID_GOOGLE_SERVICES_ID
APP_MANAGER_GOOGLE_PLAY_CREDENTIAL_ID
APP_MANAGER_IOS_CERTIFICATE_ID
APP_MANAGER_IOS_PROVISIONING_PROFILE_ID
APP_MANAGER_IOS_GOOGLE_SERVICES_ID
APP_MANAGER_APPLE_API_KEY_CREDENTIAL_ID
```

Missing, ambiguous and expired required credentials fail before Expo prebuild
starts. The plugin writes secret files with mode `0600`.

## White-label builds

`MobileApp` selects the brand. Both seeded brands build from this unchanged
source tree:

```bash
APP_MANAGER_APP=example-app \
APP_MANAGER_PLATFORM=android \
bundle exec fastlane build platform:android

APP_MANAGER_APP=example-app-blue \
APP_MANAGER_PLATFORM=android \
bundle exec fastlane build platform:android
```

The second run resolves a different name, package/bundle identifier, icon,
splash, Firebase client file, signing credential and store credential. No
source file needs to be edited between brands.

The seeded non-default Android beta target is selected explicitly:

```bash
APP_MANAGER_APP=example-app \
APP_MANAGER_IDENTIFIER=com.example.app.beta \
APP_MANAGER_PROFILE_TYPE=preview \
bundle exec fastlane build platform:android
```

For independent codebases, copy the same lane/adapter pattern into each
repository and set a different `APP_MANAGER_APP`; no shared source-tree or
white-label convention is required.

## Generated files

`prepare` downloads and generates everything under ignored `.app-manager/`:

- `expo.json` — dynamic Expo metadata for the selected platform;
- `credentials.json` — selected platform signing configuration;
- `context.json` — build/deploy manifest;
- `downloads/<app-slug>/` — icon, splash, Firebase, signing and store files.

Tracked `app.json` remains a local fallback. `app.config.js` merges
`.app-manager/expo.json` at Expo evaluation time, so prepare never rewrites a
tracked configuration file. Native `android/` and `ios/` directories and build
output are ignored and reproducible.

DemoSeeder's Apple certificate and provisioning profile are structurally valid
test fixtures but are not trusted by Apple. Replace them in Platform with real
Developer account credentials for signed distribution or store submission.
