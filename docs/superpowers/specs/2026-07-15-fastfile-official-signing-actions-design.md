# Refactor iOS signing to official Fastlane actions — design

## Background

The demo `demos/expo-hello-world/fastlane/Fastfile` currently performs iOS code
signing through a series of raw `sh("security", ...)` / `sh("plutil", ...)` /
`sh("bash", "-c", ...)` calls (in `profile_metadata`, `setup_ios_signing`,
`teardown_ios_signing`). Fastlane ships first-party actions and helpers that do
exactly this, so the hand-rolled shell plumbing can be replaced with supported,
readable calls. Android signing already uses the official `gradle` action and is
left unchanged.

## Scope

In scope:
- Replace the iOS signing shell plumbing with built-in Fastlane actions and
  `FastlaneCore::ProvisioningProfile` helpers.
- Keep the exact same externally observable behavior: `build_ios` produces the
  same signed IPA and cleans up on success and failure.

Out of scope:
- `before_all`'s `sh("npx", "expo", "prebuild")` — Expo has no first-party
  Fastlane action; this is a legitimate external-tool call, not signing plumbing,
  and stays.
- Android (`build_android`), the deploy lanes, and the `build`/`deploy`
  dispatchers — unchanged.
- `match` / `sigh` remote credential management — deliberately not adopted; the
  credentials are explicit files fetched from App Manager, so
  `import_certificate` + `FastlaneCore::ProvisioningProfile.install` is the
  correct built-in path.

## Replacements

| Current `sh(...)` | Official replacement |
|---|---|
| `security create-keychain` / `set-keychain-settings` / `unlock-keychain` | `create_keychain(unlock: true, timeout: 0, add_to_search_list: true)` |
| `security import` + `set-key-partition-list` | `import_certificate(keychain_name:, keychain_password:)` |
| `security list-keychains -s` save/restore | none needed — `create_keychain`/`delete_keychain` manage the search list |
| `security cms -D` + `plutil` (`profile_metadata`) | `FastlaneCore::ProvisioningProfile.parse(path)` |
| manual `FileUtils.cp` of the profile | `FastlaneCore::ProvisioningProfile.install(path)` |
| `security find-identity` SHA-1 regex | removed — `CODE_SIGN_IDENTITY="Apple Distribution"` + the specific profile disambiguates the certificate |
| `security delete-keychain` (teardown) | `delete_keychain(name:)` |

## Design

Requires: add `require "fastlane_core/provisioning_profile"`; remove now-unused
`require "shellwords"` and `require "fileutils"`.

Constants: replace `IOS_KEYCHAIN` (a path) + `IOS_KEYCHAIN_PASSWORD` with a
keychain *name* + password:

```ruby
IOS_KEYCHAIN_NAME = "appmanager-build.keychain"
IOS_KEYCHAIN_PASSWORD = "appmanager-build"
```

Delete `profile_metadata` and rewrite the two helpers:

```ruby
def setup_ios_signing(credentials, signing)
  cert = credentials.dig("ios", "distributionCertificate")
  UI.user_error!("No iOS distribution certificate in credentials.json") unless cert
  cert_path = File.expand_path(cert["path"], PROJECT_ROOT)
  profile_path = File.expand_path(credentials.dig("ios", "provisioningProfilePath"), PROJECT_ROOT)
  UI.user_error!("iOS provisioning profile is missing") unless File.exist?(profile_path)

  keychain_db = File.expand_path("~/Library/Keychains/#{IOS_KEYCHAIN_NAME}-db")
  delete_keychain(name: IOS_KEYCHAIN_NAME) if File.exist?(keychain_db)
  create_keychain(
    name: IOS_KEYCHAIN_NAME,
    password: IOS_KEYCHAIN_PASSWORD,
    default_keychain: false,
    unlock: true,
    timeout: 0,
    add_to_search_list: true,
  )
  signing[:keychain] = IOS_KEYCHAIN_NAME

  import_certificate(
    certificate_path: cert_path,
    certificate_password: cert["password"],
    keychain_name: IOS_KEYCHAIN_NAME,
    keychain_password: IOS_KEYCHAIN_PASSWORD,
  )

  signing[:installed_profile] = FastlaneCore::ProvisioningProfile.install(profile_path)
  parsed = FastlaneCore::ProvisioningProfile.parse(profile_path)
  { profile_name: parsed["Name"], team: parsed["TeamIdentifier"].first }
end

def teardown_ios_signing(signing)
  return unless signing
  delete_keychain(name: signing[:keychain]) if signing[:keychain]
  File.delete(signing[:installed_profile]) if signing[:installed_profile] && File.exist?(signing[:installed_profile])
end
```

`build_ios` keeps its `begin/ensure` structure. Only the `xcargs` identity term
changes — from a pinned SHA-1 to the generic name:

```ruby
      xcargs: %Q(CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=#{info[:team]} ) +
              %Q(PROVISIONING_PROFILE_SPECIFIER="#{info[:profile_name]}" ) +
              %Q(CODE_SIGN_IDENTITY="Apple Distribution"),
```

`export_options` is unchanged (`method: app-store`, `signingStyle: manual`,
`teamID:`, `provisioningProfiles: { bundle_id => profile_name }`,
`signingCertificate: "Apple Distribution"`).

Cleanup contract is preserved: `signing` is a mutable hash populated as each
side effect happens (`:keychain` after create, `:installed_profile` after
install), and the `ensure` block calls `teardown_ios_signing(signing)`, so a
raise at any point still unwinds what was done. `delete_keychain` also removes
the keychain from the search list, so no manual search-list bookkeeping remains.

## Verification

Manual (no automated test for the Ruby lane), same as the current lane:
run `npm run prepare` (pointed at an app with iOS credentials), then
`bundle exec fastlane build platforms:"ios"`, and confirm:
- `ruby -c fastlane/Fastfile` → Syntax OK.
- The lane succeeds and prints the signed-IPA success message.
- `codesign -dvvv` on the exported `.app` shows the Apple Distribution → WWDR →
  Apple Root CA chain and the expected Team ID; `codesign --verify --strict`
  passes.
- The temporary keychain is gone afterwards
  (`~/Library/Keychains/appmanager-build.keychain-db` absent) and the temp
  keychain is no longer in `security list-keychains -d user`.

## Risks / assumptions

- `CODE_SIGN_IDENTITY="Apple Distribution"` resolves to the imported certificate
  because the specific provisioning profile constrains which certificate is
  eligible. If a machine had multiple distinct Apple Distribution certs valid for
  the same profile, xcodebuild would still pick a profile-eligible one; acceptable
  for the demo.
- `FastlaneCore::ProvisioningProfile.parse` / `.install` are fastlane-internal
  APIs (used by the `sigh`/`install_provisioning_profile` actions). They are
  stable across the pinned fastlane version and are the intended way to read a
  local profile without shelling out.
- README already documents manual signing + cleanup; a wording tweak is optional
  and only if the current text becomes inaccurate (it does not).
