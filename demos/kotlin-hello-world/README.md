# Native Kotlin Hello World

Minimal native Android application written in Kotlin. App Manager supplies the
brand, `applicationId`, version/version code, icon/splash, Firebase client file,
keystore and Google Play key through `fastlane-plugin-app_manager`.

The stable Kotlin namespace is `com.example.appmanager.kotlin`; the distributable
application ID is dynamic and may differ for every white-label brand.

## Setup

Start and seed Platform as described in the Expo demo, then install Ruby gems:

```bash
cd demos/kotlin-hello-world
bundle install
```

The project currently expects a compatible Gradle executable (Gradle 8.7 or
newer) on `PATH`. Set `GRADLE_PATH` when Gradle is installed elsewhere.

## Workflows

```bash
bundle exec fastlane prepare
bundle exec fastlane build
bundle exec fastlane deploy submit:true
bundle exec fastlane clean
```

The demo supports only `APP_MANAGER_PLATFORM=android` and defaults to the
`production` profile. Common inputs are:

```text
APP_MANAGER_BASE_URL=http://localhost:8000
APP_MANAGER_API_KEY=demo-api-key
APP_MANAGER_TEAM=demo-users-team
APP_MANAGER_APP=example-app
APP_MANAGER_PLATFORM=android
APP_MANAGER_IDENTIFIER=com.example.app
APP_MANAGER_PROFILE_TYPE=production
```

The non-default seeded beta target uses:

```bash
APP_MANAGER_APP=example-app \
APP_MANAGER_IDENTIFIER=com.example.app.beta \
APP_MANAGER_PROFILE_TYPE=preview \
bundle exec fastlane build
```

## White-label

The same tracked source builds both seeded brands:

```bash
APP_MANAGER_APP=example-app bundle exec fastlane build
APP_MANAGER_APP=example-app-blue bundle exec fastlane build
```

`prepare` writes ignored `.app-manager/android.properties`, resources, signing
files and deployment context. The optional Firebase client file is written to
ignored `app/google-services.json`; the Google Services plugin is applied only
when that file exists. No Kotlin, manifest or Gradle source is rewritten.

Downloaded secrets and generated properties use mode `0600`. Deployment is
disabled unless `submit:true` or `APP_MANAGER_ALLOW_DEPLOY=1` is supplied.
