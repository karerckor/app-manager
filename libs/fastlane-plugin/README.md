# fastlane-plugin-app_manager

`fastlane-plugin-app_manager` connects Fastlane lanes to the App Manager API. It provides composable actions for application metadata, assets, mobile signing credentials, store credentials, manual build reporting, build logs, artifact uploads, and build management.

The plugin does not own the build itself. Every download action writes to the exact `output_path` selected by the caller, so the result can be combined with native Xcode/Gradle lanes, Flutter, React Native, Expo, or a custom pipeline.

The plugin targets API v1 served by `apps/platform` (locally, `http://localhost:8000`). It is currently a pre-1.0 monorepo package: pin the source revision used by CI and expect the action contract to evolve with the API.

## Quick Start

```ruby
lane :ios_build do
  app_manager_configure(
    api_key: ENV.fetch("APP_MANAGER_API_KEY"),
    team: "mobile-team",
    app: "customer-app"
  )

  info = app_manager_app_info
  certificate = app_manager_download_ios_certificate(
    profile_type: "appstore",
    output_path: "build/signing/distribution.p12"
  )

  build = app_manager_create_build(profile_type: "appstore")
  app_manager_build_start

  begin
    build_app(...)
    app_manager_add_artifact(file: "output/app.ipa")
    app_manager_build_finish(status: "completed")
  rescue => e
    app_manager_build_log(message: "Failed: #{e.message}")
    app_manager_build_finish(status: "failed")
    raise
  end
end
```

## Requirements

- Ruby 3.1 or later;
- Fastlane 2.220 or later, but earlier than Fastlane 3;
- an accessible App Manager instance;
- a team API key with the scopes needed by the selected actions;
- platform build tooling such as Xcode or the Android SDK only when the rest of the lane requires it.

## Installation

The repository currently consumes the plugin as a local Bundler dependency. Add it to a mobile project's `Gemfile` or `fastlane/Pluginfile`:

```ruby
gem "fastlane-plugin-app_manager", path: "../libs/fastlane-plugin"
```

Adjust the relative path for the consuming project, then install and verify it:

```bash
bundle install
bundle exec fastlane action app_manager_configure
```

For a source checkout outside this monorepo, pin the App Manager repository to a commit in your dependency workflow rather than following `main`. The gem has not yet established a public release and compatibility policy.

To build the package manually:

```bash
cd libs/fastlane-plugin
bundle install
gem build fastlane-plugin-app_manager.gemspec
```

## Configuration

Configure the API once near the start of a lane:

```ruby
app_manager_configure(
  api_key: ENV.fetch("APP_MANAGER_API_KEY"),
  base_url: "http://localhost:8000",
  team: "mobile-team",
  app: "customer-app"
)
```

`base_url` may be either the server URL (`http://localhost:8000`) or the versioned API URL (`http://localhost:8000/api/v1`). `team` and `app` are slugs. They are configured once and reused by every action, so one lane cannot accidentally mix assets or credentials from different applications.

The configure action is optional when all configuration comes from the environment:

```text
APP_MANAGER_API_KEY=1|plain-text-api-key
APP_MANAGER_BASE_URL=http://localhost:8000
APP_MANAGER_TEAM=mobile-team
APP_MANAGER_APP=customer-app
```

`APP_MANAGER_BASE_URL` defaults to `http://localhost:8000`. The API key is marked as a sensitive Fastlane option and is never printed by the plugin.

Explicit `app_manager_configure` arguments take effect for the current Fastlane process. If that action is not called, the client reads the four environment variables when the first App Manager action runs. Configure one app per lane execution; calling the action again replaces the in-process configuration.

For non-local use, always use an HTTPS base URL. The HTTP client uses a 10-second connection timeout and a 60-second response timeout by default. It automatically retries requests on transient errors (HTTP 429, 502, 503, 504 and network failures) up to 3 times with exponential backoff.

Customize timeout and retry behavior:

```ruby
app_manager_configure(
  api_key: ENV.fetch("APP_MANAGER_API_KEY"),
  team: "mobile-team",
  app: "customer-app",
  connect_timeout: 30,
  read_timeout: 120,
  max_retries: 5
)
```

### Required API scopes

| Operation | Minimum scopes |
| --- | --- |
| `app_manager_app_info` | `apps:read`, `identifiers:read` |
| `app_manager_list_builds`, `app_manager_show_build` | `builds:read` |
| `app_manager_cancel_build` | `pipelines:run` |
| `app_manager_list_credentials` | `credentials:read` |
| `app_manager_list_identifiers` | `identifiers:read` |
| `app_manager_download_artifact` | `artifacts:read` |
| `app_manager_list_files`, `app_manager_download_file` | `files:read` |
| `app_manager_upload_file`, `app_manager_delete_file` | `files:write` |
| Icon or splash-screen download | `assets:read` |
| Any credential download | `credentials:read`; also `identifiers:read` when `app_identifier` is omitted |
| Create/start/finish a manual build | `builds:write` |
| Append a build log | `logs:write` |
| Upload a build artifact | `artifacts:write` |

App Manager also evaluates the team permissions associated with the API key creator. A scope does not grant access that the creator's team role does not have.

## Actions

| Action | API resource | Platforms |
| --- | --- | --- |
| `app_manager_app_info` | App name, metadata and identifiers | iOS, Android |
| `app_manager_list_builds` | List builds with optional status filter | iOS, Android |
| `app_manager_show_build` | Show build details | iOS, Android |
| `app_manager_cancel_build` | Request build cancellation | iOS, Android |
| `app_manager_download_icon` | App icon | iOS, Android |
| `app_manager_download_splashscreen` | Splash screen | iOS, Android |
| `app_manager_download_ios_certificate` | Password-protected `.p12`/`.pfx` signing certificate | iOS |
| `app_manager_download_provisioning_profile` | `.mobileprovision` file | iOS |
| `app_manager_download_android_keystore` | `.jks`/`.keystore` signing file | Android |
| `app_manager_download_firebase_config` | Firebase HTTP v1 service-account JSON | iOS, Android |
| `app_manager_download_apns_key` | APNs authentication key (`.p8`) | iOS |
| `app_manager_download_google_play_service_account` | Google Play service-account JSON | Android |
| `app_manager_download_apple_api_key` | App Store Connect API key (`.p8`) | iOS |
| `app_manager_download_artifact` | Download a build artifact | iOS, Android |
| `app_manager_list_files` | List files attached to the app | iOS, Android |
| `app_manager_upload_file` | Attach a file to the app | iOS, Android |
| `app_manager_download_file` | Download a file attached to the app | iOS, Android |
| `app_manager_delete_file` | Delete a file attached to the app | iOS, Android |
| `app_manager_list_credentials` | List all credentials for the app | iOS, Android |
| `app_manager_list_identifiers` | List app identifiers (bundle IDs, package names) | iOS, Android |
| `app_manager_create_build` | Create an API-managed build in `pending` | iOS, Android |
| `app_manager_build_start` | Mark an API-managed build as `running` | iOS, Android |
| `app_manager_build_log` | Append plain-text build output | iOS, Android |
| `app_manager_build_finish` | Finish as `completed`, `failed`, or `cancelled` | iOS, Android |
| `app_manager_add_artifact` | Upload and attach a build artifact | iOS, Android |

### Action option reference

| Action group | Required options | Optional options and defaults | Return value |
| --- | --- | --- | --- |
| `app_manager_configure` | Configuration must resolve `api_key`, `team`, and `app` | `base_url` defaults to `http://localhost:8000`; `connect_timeout: 10`, `read_timeout: 60`, `max_retries: 3` | `true` |
| `app_manager_app_info` | None | None | Normalized app/identifier hash |
| `app_manager_list_builds` | None | `status` filter, `limit` count | Array of build hashes |
| `app_manager_show_build` | A resolved build ID | `build_id` defaults to lane context or `APP_MANAGER_BUILD_ID` | Build hash |
| `app_manager_cancel_build` | A resolved build ID | `build_id` defaults to lane context or `APP_MANAGER_BUILD_ID` | Updated build hash |
| `app_manager_list_credentials` | None | None | Array of credential hashes |
| `app_manager_list_identifiers` | None | None | Array of identifier hashes |
| Icon and splash-screen actions | `output_path` | `overwrite: true` | Absolute output path |
| Signing credential actions | `profile_type`, `output_path` | `app_identifier`, `credential_id`, `credential_name`, `overwrite: true` | Credential result hash |
| Service credential actions | `output_path` | `app_identifier`, `credential_id`, `credential_name`, `overwrite: true` | Credential result hash |
| `app_manager_download_artifact` | `artifact_id`, `output_path` | `build_id` defaults to lane context, `overwrite: true` | Absolute output path |
| `app_manager_create_build` | `profile_type` | `platform` defaults to the current lane and resolves its default identifier; `request_uuid` generated when absent, `app_identifier_id`, `context: {}` | Build API resource |
| `app_manager_build_start` | A resolved build ID | `build_id` defaults to lane context or `APP_MANAGER_BUILD_ID` | Updated build resource |
| `app_manager_build_log` | `message` and a resolved build ID | `newline: true`, optional `build_id` | Build-log metadata |
| `app_manager_build_finish` | A resolved build ID | `status: "completed"`; also accepts `failed`, `cancelled` | Updated build resource |
| `app_manager_add_artifact` | `file` and a resolved build ID | `name` defaults to basename, `metadata: {}`, optional `build_id` | Artifact API resource |

`app_manager_create_build` currently sends the API build `context` field but does not expose the API's separate `parameters` field. Use a pre-created build ID or the generic API/CLI when immutable user-supplied build parameters are required.

All download actions require:

- `output_path`: the exact destination file path;
- `overwrite`: whether to replace an existing file, defaults to `true`.

Credential actions accept an optional `app_identifier` (bundle ID or Android package name). When omitted, the plugin requests the configured application's identifiers and uses the declared default for the action platform. Legacy API responses without `is_default` still fall back to the first identifier. Signing actions require a `profile_type`:

- iOS: `development`, `adhoc`, `appstore`, `enterprise`;
- Android: `development`, `preview`, `production`.

iOS-only actions select the default iOS identifier, and Android-only actions select the default Android identifier. `app_manager_download_firebase_config` uses the current Fastlane platform lane (`ios` or `android`). Pass `app_identifier` explicitly when the action should target a non-default identifier.

If more than one credential matches, the action stops and lists the candidates. Pass an exact `credential_id` or `credential_name` to disambiguate it.

## App info and white-label configuration

`app_manager_app_info` combines the configured app with its identifiers and exposes the server-declared default for each platform. For legacy API responses only, it marks the first identifier encountered per platform as `default: true`:

```ruby
info = app_manager_app_info

info[:name] # "Customer App"
info[:metadata] # { "owner" => "mobile-team", "channel" => "production" }
info[:app_identifiers]
# [
#   { platform: :ios, identifier: "com.example.customer", default: true, ... },
#   { platform: :android, identifier: "com.example.customer", default: true, ... },
#   { platform: :ios, identifier: "com.example.customer.beta", default: false, ... }
# ]

info[:default_app_identifiers][:ios][:identifier]
info[:default_app_identifiers][:android][:identifier]
info[:default_app_identifiers][:ios][:version]
info[:default_app_identifiers][:android][:version_code]
```

The returned hash is also stored in `lane_context[SharedValues::APP_MANAGER_APP_INFO]`. A white-label iOS lane can apply the name and default identifier like this:

```ruby
info = app_manager_app_info
ios_identifier = info[:default_app_identifiers].fetch(:ios).fetch(:identifier)

update_app_identifier(
  xcodeproj: "ios/CustomerApp.xcodeproj",
  plist_path: "CustomerApp/Info.plist",
  app_identifier: ios_identifier
)

update_info_plist(
  plist_path: "ios/CustomerApp/Info.plist",
  display_name: info[:name]
)
```

For a Gradle project configured to accept project properties:

```ruby
info = app_manager_app_info
android_identifier = info[:default_app_identifiers].fetch(:android).fetch(:identifier)

gradle(
  task: "bundle",
  build_type: "Release",
  properties: {
    "whiteLabelAppName" => info[:name],
    "whiteLabelApplicationId" => android_identifier
  }
)
```

## iOS example

```ruby
lane :ios_release do
  app_manager_configure(
    api_key: ENV.fetch("APP_MANAGER_API_KEY"),
    base_url: ENV.fetch("APP_MANAGER_BASE_URL", "http://localhost:8000"),
    team: "mobile-team",
    app: "customer-app"
  )

  app_manager_download_icon(
    output_path: "ios/App/Assets.xcassets/AppIcon.appiconset/source.png"
  )

  app_manager_download_splashscreen(
    output_path: "ios/App/Assets.xcassets/Splash.imageset/splash.png"
  )

  certificate = app_manager_download_ios_certificate(
    profile_type: "appstore",
    output_path: "build/signing/distribution.p12"
  )

  profile = app_manager_download_provisioning_profile(
    profile_type: "appstore",
    output_path: "build/signing/appstore.mobileprovision"
  )

  apple_key = app_manager_download_apple_api_key(
    output_path: "build/submission/AuthKey.p8"
  )

  import_certificate(
    certificate_path: certificate[:path],
    certificate_password: certificate[:payload]["password"]
  )

  api_key = app_store_connect_api_key(
    key_id: apple_key[:payload]["key_id"],
    issuer_id: apple_key[:payload]["issuer_id"],
    key_filepath: apple_key[:path]
  )

  install_provisioning_profile(path: profile[:path])
  build_app(
    export_options: {
      provisioningProfiles: {
        "com.example.customer" => "Customer App Store Profile"
      }
    }
  )
  upload_to_testflight(api_key: api_key)
end
```

APNs credentials use the same return shape:

```ruby
apns = app_manager_download_apns_key(
  output_path: "build/push/AuthKey.p8"
)

apns[:payload]["key_id"]
apns[:payload]["team_id"]
```

## Android example

```ruby
lane :android_release do
  app_manager_configure(
    api_key: ENV.fetch("APP_MANAGER_API_KEY"),
    base_url: ENV.fetch("APP_MANAGER_BASE_URL", "http://localhost:8000"),
    team: "mobile-team",
    app: "customer-app"
  )

  keystore = app_manager_download_android_keystore(
    profile_type: "production",
    output_path: "android/app/release.jks"
  )

  play = app_manager_download_google_play_service_account(
    output_path: "build/submission/google-play.json"
  )

  gradle(
    task: "bundle",
    build_type: "Release",
    properties: {
      "android.injected.signing.store.file" => keystore[:path],
      "android.injected.signing.store.password" => keystore[:payload]["store_password"],
      "android.injected.signing.key.alias" => keystore[:payload]["key_alias"],
      "android.injected.signing.key.password" => keystore[:payload]["key_password"]
    }
  )

  upload_to_play_store(json_key: play[:path])
end
```

`app_manager_download_firebase_config` downloads the Firebase HTTP v1 service-account JSON stored as the Platform `firebase_config` credential. It is not the client-side `google-services.json` or `GoogleService-Info.plist` file.

The current Platform API exposes APNs authentication keys (`.p8`) through `apns_key`. It does not yet expose a separate legacy APNs certificate (`.p12`) credential type.

## Manual build reporting

Use manual reporting when App Manager cannot or should not poll the CI provider. A self-hosted runner can create and own the complete build lifecycle:

```ruby
build = app_manager_create_build(
  profile_type: "production",
  # platform defaults to the current Fastlane lane. Passing it explicitly also works.
  platform: "android",
  context: { commit: ENV["GITHUB_SHA"], runner: "self-hosted" }
)

app_manager_build_start
app_manager_build_log(message: "Resolving dependencies")

begin
  # Run the real build here.
  app_manager_build_log(message: "Artifact created")

  app_manager_add_artifact(
    file: "output/app-release.apk",
    name: "app-release.apk",
    metadata: { git_sha: ENV["GIT_SHA"] }
    # build_id defaults to APP_MANAGER_BUILD_ID from a prior app_manager_create_build
  )

  app_manager_build_finish(status: "completed")
rescue StandardError => error
  app_manager_build_log(message: "Build failed: #{error.message}")
  app_manager_build_finish(status: "failed")
  raise
end
```

## Build management

List, inspect and cancel builds:

```ruby
builds = app_manager_list_builds(status: "running")
# => [{ "id" => "...", "status" => "running", ... }, ...]

build = app_manager_show_build(build_id: "build-uuid")
# => { "id" => "build-uuid", "status" => "running", ... }

app_manager_cancel_build(build_id: "build-uuid")
```

Download an artifact from a previous build:

```ruby
app_manager_download_artifact(
  artifact_id: "artifact-uuid",
  output_path: "build/release.apk",
  build_id: "build-uuid"
)
```

## Listing credentials and identifiers

Inspect available credentials and identifiers:

```ruby
credentials = app_manager_list_credentials
# => [{ "id" => "...", "type" => "ios_certificate", "name" => "..." }, ...]

identifiers = app_manager_list_identifiers
# => [{ "id" => "...", "platform" => "ios", "identifier" => "com.example.app" }, ...]
```

## CI/CD integration

### GitHub Actions

```yaml
name: Build
on:
  workflow_dispatch:
    inputs:
      app_manager_build_id:
        required: false
        type: string

jobs:
  build:
    runs-on: macos-latest
    env:
      APP_MANAGER_BUILD_ID: ${{ inputs.app_manager_build_id }}
      APP_MANAGER_API_KEY: ${{ secrets.APP_MANAGER_API_KEY }}
      APP_MANAGER_TEAM: mobile-team
      APP_MANAGER_APP: customer-app
    steps:
      - uses: actions/checkout@v4
      - uses: ruby/setup-ruby@v1
        with:
          bundler-cache: true
      - run: bundle exec fastlane ios release
```

### Bitrise

```yaml
workflows:
  build:
    envs:
      - APP_MANAGER_API_KEY: $APP_MANAGER_API_KEY
      - APP_MANAGER_TEAM: mobile-team
      - APP_MANAGER_APP: customer-app
    steps:
      - bundle@1:
          inputs:
            - command: exec fastlane android release
```

`app_manager_create_build` stores the returned UUID in `APP_MANAGER_BUILD_ID` lane context. The other actions use that value automatically. For a CI/CD pipeline configured with manual reporting, App Manager creates the build before dispatch and injects its UUID into the selected `workflow_dispatch` string input. Map that input to the runner environment:

```yaml
on:
  workflow_dispatch:
    inputs:
      app_manager_build_id:
        required: false
        type: string

jobs:
  build:
    env:
      APP_MANAGER_BUILD_ID: ${{ inputs.app_manager_build_id }}
```

Each log request accepts a plain-text chunk up to 256 KiB; a build keeps up to 5 MB. Automatic pipelines continue to read status and logs directly from their CI provider and reject manual lifecycle writes.

Build-reporting actions resolve the build ID in this order:

1. the action's explicit `build_id` option;
2. `APP_MANAGER_BUILD_ID` in Fastlane lane context, set by `app_manager_create_build`;
3. the `APP_MANAGER_BUILD_ID` environment variable injected by CI.

This allows one lane to create a standalone manual build, while the same lane can consume a build that App Manager created before dispatching a provider pipeline.

## Return values and file handling

Asset actions return an absolute destination path. Credential actions return:

```ruby
{
  path: "/absolute/path/to/file",
  id: "credential-uuid",
  type: "android_keystore",
  name: "Production keystore",
  payload: { "store_password" => "...", "key_alias" => "...", "key_password" => "..." },
  expires_at: "2027-07-14T00:00:00Z",
  profile_type: "production",
  app_identifier: "com.example.customer"
}
```

Parent directories are created automatically. Downloads are written through a temporary file and renamed into place. Asset files use mode `0644`; credentials and private keys use mode `0600`.

The API key is sent only in the HTTPS `Authorization` header and is marked sensitive in Fastlane configuration. Credential payloads are returned to the lane because signing tools need them; do not print the result hash or include it in build metadata. Keep download paths out of source control and remove ephemeral signing files when the runner is not disposable.

## Error behavior and troubleshooting

Actions stop the lane with a Fastlane user error when configuration is missing, a URL is invalid, the API cannot be reached, an HTTP response is unsuccessful, or a response has an unexpected shape.

Common failures:

| Symptom | Likely cause |
| --- | --- |
| `401` | Missing, invalid, or revoked API key |
| `403` | The key lacks a required scope, or its creator lacks the team permission |
| `404` | Wrong team/app slug, cross-team resource, or missing asset/credential file |
| `409` during status/log updates | The build is automatic or already has a conflicting terminal state |
| `422` | Invalid build profile, payload, state, or artifact metadata |
| `Multiple App Manager credentials match` | Add `credential_id` or a unique `credential_name` |
| No platform identifier | Create an identifier or pass the correct `app_identifier` |
| Refusing to overwrite a path | Set `overwrite: true` or choose a fresh destination |

Use Fastlane's generated action help to inspect options from the installed version:

```bash
bundle exec fastlane action app_manager_download_android_keystore
bundle exec fastlane action app_manager_create_build
```

## Current boundaries

- The plugin talks to one configured team and app at a time.
- It downloads files and reports builds, but deliberately leaves compilation, signing-tool invocation, and store upload to normal Fastlane actions.
- HTTP requests automatically retry on transient errors (429, 502, 503, 504, network failures) with exponential backoff. Customize via `connect_timeout`, `read_timeout`, and `max_retries` on `app_manager_configure`.
- Log actions send one message per request; use [`appmctl`](../cli/README.md) when a command's live stdout/stderr needs to be chunked and streamed automatically.
- The Firebase action downloads the server-side Firebase HTTP v1 service account. The separate Android/iOS client configuration files are currently available through the API and demo preparation flow, not dedicated plugin actions.
- There is no stable public gem release contract yet.

## Development

```bash
bundle install
bundle exec rspec
gem build fastlane-plugin-app_manager.gemspec
```

The tests cover URL normalization and validation, API errors, credential selection, file modes and overwrite behavior, app metadata normalization, build lifecycle actions, log reporting, and artifact upload behavior. Keep the gem version, API contract, action documentation, and demo Fastfile aligned when adding an action.
