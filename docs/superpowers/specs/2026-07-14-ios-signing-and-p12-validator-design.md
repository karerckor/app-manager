# iOS Fastlane signing + legacy PKCS#12 validator — design

## Background

Two gaps surfaced while verifying an iOS build for `appfront-sandbox` with real
EAS-exported Apple credentials fetched from App Manager:

1. **Platform rejects real `.p12` files.** `ValidCredentialFile::validatePkcs12`
   uses PHP's `openssl_pkcs12_read`, which on OpenSSL 3 cannot read
   legacy-encrypted PKCS#12 files (RC2/3DES) — the format EAS and Apple still
   export. Result: `error:0308010C:digital envelope routines::unsupported`, so a
   valid certificate is wrongly rejected on upload.

2. **Demo `build_ios` lane does not sign.** Unlike `build_android` (which injects
   the keystore), `build_ios` calls `build_app` with no signing setup, so it
   fails with `Signing for "AppfrontSandbox" requires a development team`. The
   downloaded certificate/profile are never wired into signing. Separately,
   `pod install` fails under `bundle exec` because CocoaPods is not in the
   Gemfile.

This spec covers fixing all three so an iOS release IPA can be built and signed
from App Manager credentials via Fastlane (no store submission).

## Scope

In scope:
- Fix the p12 validator to accept legacy-encrypted PKCS#12 files.
- Wire iOS signing into the demo `build_ios` Fastlane lane.
- Add `cocoapods` to the demo Gemfile so `pod install` works under `bundle exec`.

Out of scope:
- Store submission (TestFlight/App Store upload) — lanes stop at the signed IPA.
- Any API/schema change (team ID and profile name are derived at build time).
- Automatic (Xcode-managed) signing — the lane uses manual signing.

## A. Platform: legacy PKCS#12 support

File: `apps/platform/app/Rules/ValidCredentialFile.php`, method `validatePkcs12`.

Behavior:
1. Try the existing fast path: `openssl_pkcs12_read($contents, $certs, $password)`.
   On success → valid (unchanged; modern files stay fast, no shell-out).
2. On failure, fall back to the OpenSSL CLI, which supports the legacy provider:
   - Write `$contents` to a temporary file (`tempnam`).
   - Run `openssl pkcs12 -legacy -in <tmp> -passin pass:<password> -noout`
     (arguments passed as an array via `Symfony\Component\Process\Process` or
     `escapeshellarg` — never interpolate the password into a shell string).
   - Exit code `0` → valid. Non-zero → fail with the existing message
     `'The PKCS#12 file is invalid or the certificate password is incorrect.'`
   - Always delete the temp file (even on exception).
3. If the `openssl` binary is unavailable, the fall-back treats the file as
   invalid (native read already failed) — the existing error message stands.

Rationale: the rule only needs a yes/no validity + password check, not the parsed
material, so `-noout` is sufficient. The two-step approach keeps modern files on
the native path and only shells out for legacy files.

Tests (`apps/platform/tests/Feature/CredentialInputValidationTest.php` or a
focused new test): 
- A modern p12 (AES) with correct password passes (guards the fast path).
- A legacy p12 (generated in the test via `openssl ... -export -legacy`) with the
  correct password passes — this fails before the fix.
- A legacy p12 with the wrong password fails validation.
- A non-p12 / corrupt file fails validation.
Fixtures are generated at test runtime with the `openssl` CLI (already a
dependency of the fix), so no binary blobs are committed.

## B. Demo Fastfile: iOS manual signing

File: `demos/expo-hello-world/fastlane/Fastfile`, lane `build_ios`.

Inputs already available after `prepare`:
- `credentials.json` → `ios.distributionCertificate.path`, `.password`,
  `ios.provisioningProfilePath` (paths relative to project root).
- The `.mobileprovision` itself yields the Apple Team ID and profile `Name`.
- `context.json` → `ios.bundleIdentifier`.

Signing setup (all inside `build_ios`, before `build_app`):
1. Create a dedicated temporary keychain (fixed name under the project, e.g.
   `fastlane/build.keychain-db`) with a throwaway password; unlock it and disable
   the auto-lock timeout.
2. Import the distribution `.p12` into that keychain with its password; run
   `security set-key-partition-list -S apple-tool:,apple:` so `codesign` can use
   the key non-interactively.
3. Prepend the temp keychain to the **user search list while keeping the existing
   login keychain**, so the WWDR intermediate and Apple root that anchor the chain
   are resolved from the system/login keychain (rely-on-system trust). Do not pass
   a restrictive `--keychain` to codesign.
4. Read the code-signing identity SHA-1 from `security find-identity -v -p
   codesigning <temp keychain>`; abort with a clear error if none is valid.
5. Install the provisioning profile: extract its UUID
   (`security cms -D -i <profile> | plutil -extract UUID raw -`) and copy it to
   `~/Library/MobileDevice/Provisioning Profiles/<UUID>.mobileprovision`.
6. Derive `teamID` (`Entitlements → com.apple.developer.team-identifier` or
   `application-identifier` prefix) and profile `Name` from the decoded profile.
7. Build with `build_app`:
   - `export_method: 'app-store'`
   - `export_options`: `{ method: 'app-store', signingStyle: 'manual',
     teamID: <team>, provisioningProfiles: { <bundleId> => <profileName> },
     signingCertificate: 'Apple Distribution' }`
   - `xcargs`: `CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=<team>
     PROVISIONING_PROFILE_SPECIFIER=<profileName>
     CODE_SIGN_IDENTITY=<identity SHA-1>` (SHA-1 avoids quoting a name with
     spaces).
   - `output_directory: build`.

Cleanup — in an `ensure` block so it runs on success or failure:
- Restore the user keychain search list (remove the temp keychain).
- Delete the temp keychain file.
- Remove the installed `.mobileprovision`.

The lane remains submission-free; `deploy_ios` (upload) is unchanged.

Verification: manual — run `npm run prepare` (pointed at an app with iOS
credentials) then `bundle exec fastlane build platforms:"ios"`, and confirm a
signed IPA whose `codesign -dvvv` shows the expected Apple Distribution authority
chain, team, and embedded profile, and that `codesign --verify --strict` passes.
There is no automated test for the Ruby lane.

## C. Demo Gemfile: CocoaPods under bundler

File: `demos/expo-hello-world/Gemfile`.

Add `gem "cocoapods"`. Under `bundle exec fastlane`, `expo prebuild` runs
`pod install`; without CocoaPods in the bundle, bundler cannot find the `pod`
executable and prebuild fails. Adding the gem makes `pod` resolvable in the
bundled environment.

## Documentation

Update `demos/expo-hello-world/README.md` to note that `build_ios` now performs
manual signing from the downloaded credentials (temporary keychain + profile
install, cleaned up afterward), and that CocoaPods is provided via the Gemfile.

## Risks / assumptions

- **Rely-on-system trust:** the chain resolves only if the WWDR intermediate and
  Apple root are present in the system/login keychain (true on a normal
  Xcode-equipped Mac). If a machine lacks them, `build_app` fails with
  `unable to build chain to self-signed root`; documented as a known limitation.
- The `openssl` CLI must be on `PATH` for the legacy p12 fall-back (standard on
  macOS/Linux CI).
