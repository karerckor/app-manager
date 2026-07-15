# Refactor iOS Signing to Official Fastlane Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the raw `sh("security"/"plutil"/"bash")` iOS-signing plumbing in the demo Fastfile with built-in Fastlane actions and `FastlaneCore::ProvisioningProfile` helpers, preserving behavior.

**Architecture:** Rewrite the three signing helpers and the `build_ios` xcargs identity term in `demos/expo-hello-world/fastlane/Fastfile`. Keychain lifecycle via `create_keychain`/`delete_keychain`, certificate via `import_certificate`, profile via `FastlaneCore::ProvisioningProfile.install`/`.parse`, identity via the generic `"Apple Distribution"` name.

**Tech Stack:** Ruby, Fastlane 2.237.0 (built-in actions), Xcode.

## Global Constraints

- Only `demos/expo-hello-world/fastlane/Fastfile` changes.
- Preserve externally observable behavior: `build_ios` builds the same signed IPA and cleans up (temp keychain + installed profile) on success AND failure.
- Do not use `match`/`sigh` remote management; credentials are explicit files from App Manager.
- Leave `before_all` (`expo prebuild`), `build_android`, `deploy_*`, and the `build`/`deploy` dispatchers unchanged.
- `signing` stays a mutable hash populated as side effects happen, consumed by the `ensure` teardown.

---

### Task 1: Swap iOS signing shell plumbing for official actions

**Files:**
- Modify: `demos/expo-hello-world/fastlane/Fastfile`

**Interfaces:**
- Consumes: built-in actions `create_keychain`, `delete_keychain`, `import_certificate`; `FastlaneCore::ProvisioningProfile.install`/`.parse`; `credentials.json` keys `ios.distributionCertificate.{path,password}` and `ios.provisioningProfilePath`; `context.json` key `ios.bundleIdentifier`.
- Produces: `build/*.ipa` signed identically to before (Apple Distribution identity + the App Store profile), with no `security`/`plutil` shell calls in the signing path.

This task has no automated test (Ruby lane). Verify manually per the steps below.

- [ ] **Step 1: Update the requires**

At the top of `demos/expo-hello-world/fastlane/Fastfile`, replace:

```ruby
require "json"
require "fileutils"
require "shellwords"
```

with:

```ruby
require "json"
require "fastlane_core/provisioning_profile"
```

- [ ] **Step 2: Replace the keychain constants**

Replace:

```ruby
IOS_KEYCHAIN = File.expand_path("build.keychain-db", __dir__)
IOS_KEYCHAIN_PASSWORD = "fastlane-build"
```

with:

```ruby
IOS_KEYCHAIN_NAME = "appmanager-build.keychain"
IOS_KEYCHAIN_PASSWORD = "appmanager-build"
```

- [ ] **Step 3: Delete `profile_metadata` and rewrite the two signing helpers**

Delete the entire `profile_metadata` method (currently lines 26-38). Replace the `setup_ios_signing` and `teardown_ios_signing` methods with:

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

- [ ] **Step 4: Update the `build_ios` xcargs identity term**

In the `build_ios` lane, in the `build_app` call, replace the `xcargs:` value:

```ruby
      xcargs: %Q(CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=#{info[:team]} ) +
              %Q(PROVISIONING_PROFILE_SPECIFIER="#{info[:profile_name]}" ) +
              %Q(CODE_SIGN_IDENTITY=#{info[:identity]}),
```

with (generic identity name; `info` no longer carries `:identity`):

```ruby
      xcargs: %Q(CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=#{info[:team]} ) +
              %Q(PROVISIONING_PROFILE_SPECIFIER="#{info[:profile_name]}" ) +
              %Q(CODE_SIGN_IDENTITY="Apple Distribution"),
```

Leave the rest of `build_ios` (the `signing = {}` init, `begin`/`ensure`, `build_app` `export_options`, success message) exactly as-is.

- [ ] **Step 5: Verify the file parses**

Run (from `demos/expo-hello-world`): `ruby -c fastlane/Fastfile`
Expected: `Syntax OK`.

- [ ] **Step 6: Ensure iOS credentials are present, then build**

The demo working tree should already be prepared for `appfront-sandbox`. If `credentials/ios/distribution.p12` is missing, re-run prepare first:

```bash
cd demos/expo-hello-world
[ -f credentials/ios/distribution.p12 ] || { rm -rf android ios; \
  APP_MANAGER_APP=appfront-sandbox \
  APP_MANAGER_ANDROID_IDENTIFIER=sandbox.appfront.ai APP_MANAGER_ANDROID_PROFILE_TYPE=production \
  APP_MANAGER_IOS_IDENTIFIER=sandbox.appfront.ai APP_MANAGER_IOS_PROFILE_TYPE=appstore \
  node scripts/prepare.mjs; }
```

Then build (the archive takes several minutes — wait for it):

```bash
cd demos/expo-hello-world
bundle exec fastlane build platforms:"ios"
```

Expected: lane succeeds, prints `Built sandbox.appfront.ai signed release IPA.`

- [ ] **Step 7: Verify the signature and cleanup**

```bash
rm -rf /tmp/ipa_refactor && mkdir /tmp/ipa_refactor
(cd /tmp/ipa_refactor && unzip -q /Users/igor/Projects/Personal/app-manager/demos/expo-hello-world/build/*.ipa)
APP=$(ls -d /tmp/ipa_refactor/Payload/*.app)
codesign -dvvv "$APP" 2>&1 | grep -E "^Authority|^TeamIdentifier|^Identifier"
codesign --verify --strict --verbose=2 "$APP"
test ! -f ~/Library/Keychains/appmanager-build.keychain-db && echo "temp keychain cleaned"
security list-keychains -d user | grep -q appmanager-build && echo "STILL IN SEARCH LIST (bad)" || echo "not in search list (good)"
```

Expected: authority chain `Apple Distribution: Appfront A.I. LTD (LUVN6C8267)` → `Apple Worldwide Developer Relations Certification Authority` → `Apple Root CA`; `TeamIdentifier=LUVN6C8267`; `valid on disk` / `satisfies its Designated Requirement`; `temp keychain cleaned`; `not in search list (good)`.

- [ ] **Step 8: Commit**

```bash
git add demos/expo-hello-world/fastlane/Fastfile
git commit -m "refactor(demo): use official Fastlane actions for iOS signing"
```

---

## Self-Review

- **Spec coverage:** requires update → Step 1; constants → Step 2; helper rewrite (create_keychain/import_certificate/install/parse, no find-identity, no manual search-list) → Step 3; generic identity in xcargs → Step 4; verification (parse + build + codesign + cleanup + search-list) → Steps 5-7. All spec points covered.
- **Placeholders:** none — full before/after code given.
- **Type consistency:** `setup_ios_signing` now returns `{ profile_name:, team: }` (no `:identity`); Step 4 removes the only `info[:identity]` reader; `signing` keys `:keychain`/`:installed_profile` are written in setup and read in `teardown_ios_signing`. Consistent.
- **Behavior preservation:** export_options and the begin/ensure cleanup structure are unchanged; only the mechanism (official actions vs sh) and the identity term (name vs SHA-1) change.
