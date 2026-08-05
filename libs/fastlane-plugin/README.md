# fastlane-plugin-app_manager

Composable Fastlane actions for credentials, application assets and optional
build lifecycle integration with App Manager. A lane can download one signing
file and stop there, or opt into build tracking, logs and remote cancellation.

The plugin works with any compatible App Manager API v1 instance, including a
self-hosted instance. It has no dependency on a particular App Manager host.
Every download action writes to the exact `output_path` selected by the caller,
so actions can be combined with native, Flutter, React Native, Expo or other
mobile build pipelines.

## Installation

Add the plugin to a mobile project's `fastlane/Pluginfile`. During monorepo development, point Bundler at this directory:

```ruby
gem "fastlane-plugin-app_manager", path: "../libs/fastlane-plugin"
```

Adjust the relative path when the mobile project is not at the monorepo root.

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

`base_url` may be the instance URL (`https://manager.example`), an instance
mounted below a path (`https://tools.example/mobile/app-manager`), or the exact
versioned API URL (`https://manager.example/api/v1`). The plugin appends
`/api/v1` unless the URL already ends with it. `team` and `app` are slugs. They
are configured once and reused by every action, so one lane cannot accidentally
mix assets or credentials from different applications.

The configure action is optional when all configuration comes from the environment:

```text
APP_MANAGER_API_KEY=1|plain-text-api-key
APP_MANAGER_BASE_URL=https://manager.example
APP_MANAGER_TEAM=mobile-team
APP_MANAGER_APP=customer-app
```

`APP_MANAGER_BASE_URL` defaults to `http://localhost:8000` for local development;
set it explicitly in CI and production. The API key is marked as a sensitive
Fastlane option and is never printed by the plugin.

## Choose the integration depth

Actions are opt-in. Installing the plugin does not register a build, upload a
log, or poll for cancellation. The API key only needs the abilities required by
the actions used in that lane:

| Use case | Actions | Minimum API key abilities |
| --- | --- | --- |
| App metadata and assets | `app_manager_app_info`, asset downloads | `apps:read` |
| Credentials only | credential downloads | `apps:read`, `secrets:read` |
| Deterministic build inputs | `app_manager_build_context`, credential downloads | `apps:read`, `secrets:read` |
| Build tracking | `app_manager_build_start`, `app_manager_build_status`, `app_manager_build_finish` | `apps:read`, `builds:read`, `builds:write` |
| Build logs | `app_manager_build_log` or `upload_logs: true` | `apps:read`, `builds:read`, `builds:write` |
| Remote cancellation | `app_manager_build_status` or `check_cancellation: true` | `apps:read`, `builds:read`, `builds:write` |

Abilities are cumulative only when the corresponding use cases are combined.
For example, a credentials-only lane does not need either `builds` ability and
does not need an external build record:

```ruby
lane :android_signing do
  keystore = app_manager_download_android_keystore(
    profile_type: "production",
    output_path: "build/signing/release.jks"
  )

  # Pass keystore[:path] and keystore[:payload] to the project's own build flow.
end
```

## Actions

| Action | API resource | Platforms |
| --- | --- | --- |
| `app_manager_app_info` | App name, metadata and identifiers | iOS, Android |
| `app_manager_build_context` | Selected target, profile, assets and credential candidates | iOS, Android |
| `app_manager_build_start` | Claim external build and load immutable target versions | iOS, Android |
| `app_manager_build_status` | Poll status and stop when remotely cancelled | iOS, Android |
| `app_manager_build_log` | Append one sequenced log entry | iOS, Android |
| `app_manager_build_finish` | Complete, fail or cancel the current build | iOS, Android |
| `app_manager_run_build` | Run a tracked command with independently optional logs and cancellation | iOS, Android |
| `app_manager_download_icon` | App icon | iOS, Android |
| `app_manager_download_splashscreen` | Splash screen | iOS, Android |
| `app_manager_download_ios_certificate` | Password-protected `.p12`/`.pfx` signing certificate | iOS |
| `app_manager_download_provisioning_profile` | `.mobileprovision` file | iOS |
| `app_manager_download_android_keystore` | `.jks`/`.keystore` signing file | Android |
| `app_manager_download_firebase_config` | Firebase HTTP v1 service-account JSON | iOS, Android |
| `app_manager_download_apns_key` | APNs authentication key (`.p8`) | iOS |
| `app_manager_download_google_play_service_account` | Google Play service-account JSON | Android |
| `app_manager_download_apple_api_key` | App Store Connect API key (`.p8`) | iOS |
| `app_manager_download_google_services_json` | Android client `google-services.json` | Android |
| `app_manager_download_google_service_info_plist` | iOS client `GoogleService-Info.plist` | iOS |

All download actions require `output_path`, the exact destination file path,
and accept `overwrite`, which controls whether an existing file is replaced and
defaults to `true`.

Credential download actions additionally accept `allow_expired`, which defaults to
`false`.

Credential actions accept an optional `app_identifier` (bundle ID or Android package name). When omitted, the plugin requests the configured application's identifiers and uses the explicit API default for the action platform. A legacy API response without `is_default` falls back to the first identifier for backward compatibility. Signing actions require a `profile_type`:

- iOS: `development`, `adhoc`, `appstore`, `enterprise`;
- Android: `development`, `preview`, `production`.

iOS-only actions select the default iOS identifier, and Android-only actions select the default Android identifier. `app_manager_download_firebase_config` uses the current Fastlane platform lane (`ios` or `android`). Pass `app_identifier` explicitly when the app has multiple identifiers for a platform and the build should not use its default target.

If more than one credential matches, the action stops and lists the candidates. Pass an exact `credential_id` or `credential_name` to disambiguate it.

Expired credentials are excluded before ambiguity is evaluated. If every matching credential is expired, the action stops before downloading a file. `allow_expired: true` is available for exceptional recovery workflows and should not be used by normal release lanes.

## Build context

`app_manager_build_context` loads the versioned App Manager build contract for one platform/profile. `platform` defaults to the current Fastlane platform lane; `profile_type` is required:

```ruby
context = app_manager_build_context(
  profile_type: "appstore",
  app_identifier: ENV["APP_MANAGER_IDENTIFIER"]
)

context[:app][:name]
context[:target][:identifier]
context[:target][:version]
context[:target][:build_number]
context[:profile][:artifact]
context[:assets]["icon"]
context[:credentials]
```

The same hash is stored in `lane_context[SharedValues::APP_MANAGER_BUILD_CONTEXT]`. `credentials` contains compatible candidates and preserves string keys in payload/file metadata. Typed download actions remain responsible for exact credential selection and secure file download.

## Externally registered builds

Register the build in App Manager before dispatching a pipeline and pass its
`external_id` as `APP_MANAGER_BUILD_EXTERNAL_ID`. Fastlane atomically claims the
pending build and receives the exact versions captured at registration:

```ruby
build = app_manager_build_start(
  external_id: ENV.fetch("APP_MANAGER_BUILD_EXTERNAL_ID")
)

build[:target][:identifier]
build[:target][:version]
build[:target][:version_code]  # Android
build[:target][:build_number]  # iOS
```

On GitHub Actions the plugin automatically uses `GITHUB_RUN_ID` and constructs
the Actions run URL. Other CI systems can pass `ci_provider`, `ci_run_id` and
`ci_run_url` explicitly. If App Manager cancelled the build before the runner claimed
it, `app_manager_build_start` stops the lane. For lanes composed of native
Fastlane actions, report checkpoints and check cancellation between expensive
stages:

```ruby
app_manager_build_log(message: "Signing material prepared", stream: "system")
app_manager_build_status # stops the lane if the build is now cancelled

# build_app(...) or gradle(...)

app_manager_build_finish(
  status: "completed",
  artifact_url: ENV["PUBLISHED_ARTIFACT_URL"]
)
```

For a shell-driven build, `app_manager_run_build` manages the complete lifecycle:

```ruby
app_manager_run_build(
  external_id: ENV.fetch("APP_MANAGER_BUILD_EXTERNAL_ID"),
  command: "./gradlew bundleRelease",
  artifact_url: ENV["PUBLISHED_ARTIFACT_URL"],
  upload_logs: true,
  check_cancellation: true,
  poll_interval: 5
)
```

Both optional features default to `true` for backward compatibility. Set either
one independently: `upload_logs: false` keeps cancellation without sending
command output; `check_cancellation: false` keeps logs without polling. Setting
both to `false` leaves only start/finish build tracking. When enabled, logs are
sent in idempotent batches and cancellation terminates the process group. The
command output remains visible in the Fastlane log in every mode. The API key
and Bearer values are redacted before display and upload. The command receives
`APP_MANAGER_BUILD_IDENTIFIER`,
`APP_MANAGER_BUILD_VERSION`, `APP_MANAGER_BUILD_VERSION_CODE`,
`APP_MANAGER_BUILD_NUMBER`, `APP_MANAGER_BUILD_PLATFORM` and
`APP_MANAGER_BUILD_PROFILE` from the immutable snapshot. Commands that invoke
separate Fastlane actions should use the explicit start/log/status/finish flow
above.

## App info and white-label configuration

`app_manager_app_info` combines the configured app with its identifiers. It preserves identifier order from the API and exposes the server-declared default as `default: true`:

```ruby
info = app_manager_app_info

info[:name] # "Customer App"
info[:app_identifiers]
# [
#   { platform: :ios, identifier: "com.example.customer", default: true, ... },
#   { platform: :android, identifier: "com.example.customer", default: true, ... },
#   { platform: :ios, identifier: "com.example.customer.beta", default: false, ... }
# ]

info[:default_app_identifiers][:ios][:identifier]
info[:default_app_identifiers][:android][:identifier]
info[:default_app_identifiers][:ios][:version]
info[:default_app_identifiers][:ios][:build_number]
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

In a white-label CI matrix, keep the source tree unchanged and configure one
`MobileApp` slug per job:

```bash
APP_MANAGER_APP=example-app bundle exec fastlane android release
APP_MANAGER_APP=example-app-blue bundle exec fastlane android release
```

Each job receives the selected brand's name, identifiers, assets and credentials.

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

Use the client actions for files embedded into the application:

```ruby
app_manager_download_google_services_json(
  output_path: "android/app/google-services.json"
)

app_manager_download_google_service_info_plist(
  output_path: "ios/App/GoogleService-Info.plist"
)
```

The current Platform API exposes APNs authentication keys (`.p8`) through `apns_key`. It does not yet expose a separate legacy APNs certificate (`.p12`) credential type.

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

## Development

```bash
bundle install
bundle exec rspec
gem build fastlane-plugin-app_manager.gemspec
```
