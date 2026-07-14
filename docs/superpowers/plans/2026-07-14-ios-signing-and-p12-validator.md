# iOS Fastlane Signing + Legacy p12 Validator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accept legacy-encrypted PKCS#12 files on upload, and make the demo `build_ios` Fastlane lane produce a signed IPA from App Manager credentials (no submission).

**Architecture:** Add an OpenSSL-CLI legacy fallback to the platform's `ValidCredentialFile` p12 check. Rewrite the demo `build_ios` lane to set up a temporary keychain + provisioning profile from the downloaded credentials and build with manual signing, cleaning up afterward. Add CocoaPods to the demo Gemfile so `pod install` works under `bundle exec`.

**Tech Stack:** PHP 8 / Laravel (platform), PHPUnit; Ruby / Fastlane (demo); OpenSSL CLI; Xcode.

## Global Constraints

- Platform work is under `apps/platform/`; demo work under `demos/expo-hello-world/`.
- Do not pass a password to a shell as an interpolated string — use `Symfony\Component\Process\Process` with an argument array.
- The p12 fast path (`openssl_pkcs12_read`) stays first; the CLI fallback runs only when it fails.
- Lanes remain submission-free; `deploy_ios` is unchanged.
- iOS signing is manual; trust chain relies on the system/login keychain (no CA import).
- No API/schema change: team ID and profile name are derived from the `.mobileprovision` at build time.

---

### Task 1: Platform — accept legacy PKCS#12 files

**Files:**
- Modify: `apps/platform/app/Rules/ValidCredentialFile.php` (add `use Symfony\Component\Process\Process;`; replace `validatePkcs12`; add a private helper)
- Test: `apps/platform/tests/Unit/ValidCredentialFileTest.php` (create)

**Interfaces:**
- Consumes: `Symfony\Component\Process\Process` (already vendored), `openssl` CLI on PATH.
- Produces: `ValidCredentialFile` now passes legacy p12 files with the correct password; behavior for modern p12 / wrong password / corrupt files is unchanged.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/ValidCredentialFileTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\CredentialType;
use App\Rules\ValidCredentialFile;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ValidCredentialFileTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workdir = sys_get_temp_dir().'/p12test-'.uniqid();
        mkdir($this->workdir);
        $this->run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $this->path('key.pem'), '-out', $this->path('cert.pem'),
            '-days', '1', '-subj', '/CN=Test Signing']);
        // modern (OpenSSL 3 default) and legacy-encrypted p12, same password
        $this->run(['openssl', 'pkcs12', '-export', '-inkey', $this->path('key.pem'),
            '-in', $this->path('cert.pem'), '-out', $this->path('modern.p12'),
            '-passout', 'pass:secret', '-name', 'test']);
        $this->run(['openssl', 'pkcs12', '-export', '-legacy', '-inkey', $this->path('key.pem'),
            '-in', $this->path('cert.pem'), '-out', $this->path('legacy.p12'),
            '-passout', 'pass:secret', '-name', 'test']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->workdir.'/*'));
        rmdir($this->workdir);
        parent::tearDown();
    }

    public function test_accepts_a_modern_pkcs12_with_correct_password(): void
    {
        $this->assertSame([], $this->errorsFor('modern.p12', 'secret'));
    }

    public function test_accepts_a_legacy_encrypted_pkcs12_with_correct_password(): void
    {
        $this->assertSame([], $this->errorsFor('legacy.p12', 'secret'));
    }

    public function test_rejects_a_legacy_pkcs12_with_the_wrong_password(): void
    {
        $this->assertNotEmpty($this->errorsFor('legacy.p12', 'wrong'));
    }

    public function test_rejects_a_file_that_is_not_a_pkcs12(): void
    {
        file_put_contents($this->path('bogus.p12'), 'not a certificate');
        $this->assertNotEmpty($this->errorsFor('bogus.p12', 'secret'));
    }

    private function errorsFor(string $file, string $password): array
    {
        $rule = new ValidCredentialFile(CredentialType::IosCertificate, ['password' => $password]);
        $upload = new UploadedFile($this->path($file), $file, 'application/x-pkcs12', null, true);
        $errors = [];
        $rule->validate('file', $upload, function ($message) use (&$errors) {
            $errors[] = $message;
        });

        return $errors;
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->markTestSkipped('openssl CLI unavailable: '.$process->getErrorOutput());
        }
    }

    private function path(string $name): string
    {
        return $this->workdir.'/'.$name;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ValidCredentialFileTest`
Expected: `test_accepts_a_legacy_encrypted_pkcs12_with_correct_password` FAILS (native `openssl_pkcs12_read` cannot read the legacy file, so the rule reports the invalid-p12 error). Other tests pass.

- [ ] **Step 3: Add the `use` import**

In `apps/platform/app/Rules/ValidCredentialFile.php`, add after the existing `use Illuminate\Http\UploadedFile;`:

```php
use Symfony\Component\Process\Process;
```

- [ ] **Step 4: Replace `validatePkcs12` and add the fallback helper**

Replace the existing `validatePkcs12` method with:

```php
    private function validatePkcs12(string $contents, Closure $fail): void
    {
        $certificates = [];
        $password = (string) ($this->payload['password'] ?? '');

        if (@openssl_pkcs12_read($contents, $certificates, $password)) {
            return;
        }

        if ($this->readableWithOpensslCli($contents, $password)) {
            return;
        }

        $fail('The PKCS#12 file is invalid or the certificate password is incorrect.');
    }

    private function readableWithOpensslCli(string $contents, string $password): bool
    {
        $temp = tempnam(sys_get_temp_dir(), 'p12');

        if ($temp === false) {
            return false;
        }

        try {
            file_put_contents($temp, $contents);

            $process = new Process([
                'openssl', 'pkcs12', '-legacy',
                '-in', $temp,
                '-passin', 'pass:'.$password,
                '-noout',
            ]);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($temp);
        }
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=ValidCredentialFileTest`
Expected: PASS (4/4). Output pristine.

- [ ] **Step 6: Run the credential validation suite for regressions**

Run: `php artisan test --filter=CredentialInputValidationTest`
Expected: PASS (no regressions).

- [ ] **Step 7: Commit**

```bash
git add apps/platform/app/Rules/ValidCredentialFile.php apps/platform/tests/Unit/ValidCredentialFileTest.php
git commit -m "fix(platform): accept legacy-encrypted PKCS#12 credential files"
```

---

### Task 2: Demo — iOS manual signing in Fastlane

**Files:**
- Modify: `demos/expo-hello-world/fastlane/Fastfile` (rewrite `build_ios`; add signing helpers)
- Modify: `demos/expo-hello-world/Gemfile` (add `cocoapods`)
- Modify: `demos/expo-hello-world/README.md` (document iOS signing + CocoaPods)

**Interfaces:**
- Consumes: `credentials.json` (`ios.distributionCertificate.path`, `.password`, `ios.provisioningProfilePath`), `context.json` (`ios.bundleIdentifier`), the downloaded `.mobileprovision`, `security`/`plutil`/`codesign` CLIs. Depends on Task 1 (so App Manager can store the original legacy p12 that macOS `security import` accepts).
- Produces: `build/*.ipa`, signed with the downloaded Apple Distribution identity and profile.

This task has no automated test (Ruby lane). Verify manually per the steps below.

- [ ] **Step 1: Add CocoaPods to the Gemfile**

Replace `demos/expo-hello-world/Gemfile` contents with:

```ruby
source "https://rubygems.org"

gem "fastlane"
gem "cocoapods"
```

- [ ] **Step 2: Install the updated bundle**

Run (from `demos/expo-hello-world`): `bundle install`
Expected: bundle resolves with cocoapods; `bundle exec pod --version` prints a version.

- [ ] **Step 3: Rewrite `build_ios` with signing**

In `demos/expo-hello-world/fastlane/Fastfile`, add these constants near the top (after the existing `PROJECT_ROOT` line):

```ruby
require "fileutils"
require "shellwords"

IOS_KEYCHAIN = File.expand_path("build.keychain-db", __dir__)
IOS_KEYCHAIN_PASSWORD = "fastlane-build"
```

Add these helper methods (below `deploy_allowed?`):

```ruby
def profile_metadata(profile_path)
  decoded = "#{profile_path}.plist"
  sh("bash", "-c", "security cms -D -i #{profile_path.shellescape} > #{decoded.shellescape}")
  meta = {
    uuid: sh("plutil", "-extract", "UUID", "raw", "-o", "-", decoded).strip,
    name: sh("plutil", "-extract", "Name", "raw", "-o", "-", decoded).strip,
    team: sh("plutil", "-extract", "TeamIdentifier.0", "raw", "-o", "-", decoded).strip,
  }
  File.delete(decoded) if File.exist?(decoded)
  meta
end

def setup_ios_signing(credentials)
  cert = credentials.dig("ios", "distributionCertificate")
  UI.user_error!("No iOS distribution certificate in credentials.json") unless cert
  cert_path = File.expand_path(cert["path"], PROJECT_ROOT)
  profile_path = File.expand_path(credentials.dig("ios", "provisioningProfilePath"), PROJECT_ROOT)
  UI.user_error!("iOS provisioning profile is missing") unless File.exist?(profile_path)

  sh("security", "delete-keychain", IOS_KEYCHAIN) if File.exist?(IOS_KEYCHAIN)
  # log: false on commands carrying the keychain or certificate password (avoid leaking secrets to the fastlane log)
  sh("security", "create-keychain", "-p", IOS_KEYCHAIN_PASSWORD, IOS_KEYCHAIN, log: false)
  sh("security", "set-keychain-settings", IOS_KEYCHAIN)
  sh("security", "unlock-keychain", "-p", IOS_KEYCHAIN_PASSWORD, IOS_KEYCHAIN, log: false)
  sh("security", "import", cert_path, "-k", IOS_KEYCHAIN, "-P", cert["password"],
     "-T", "/usr/bin/codesign", "-T", "/usr/bin/security", "-A", log: false)
  sh("security", "set-key-partition-list", "-S", "apple-tool:,apple:",
     "-s", "-k", IOS_KEYCHAIN_PASSWORD, IOS_KEYCHAIN, log: false)

  previous = sh("security", "list-keychains", "-d", "user").scan(/"([^"]+)"/).flatten
  sh("security", "list-keychains", "-d", "user", "-s", IOS_KEYCHAIN, *previous)

  identity = sh("security", "find-identity", "-v", "-p", "codesigning", IOS_KEYCHAIN)[/\b([0-9A-F]{40})\b/, 1]
  UI.user_error!("No valid iOS signing identity after import (missing WWDR/root in system keychain?)") unless identity

  meta = profile_metadata(profile_path)
  profiles_dir = File.expand_path("~/Library/MobileDevice/Provisioning Profiles")
  FileUtils.mkdir_p(profiles_dir)
  installed_profile = File.join(profiles_dir, "#{meta[:uuid]}.mobileprovision")
  FileUtils.cp(profile_path, installed_profile)

  { identity: identity, profile_name: meta[:name], team: meta[:team],
    previous_keychains: previous, installed_profile: installed_profile }
end

def teardown_ios_signing(state)
  return unless state
  sh("security", "list-keychains", "-d", "user", "-s", *state[:previous_keychains]) if state[:previous_keychains]
  sh("security", "delete-keychain", IOS_KEYCHAIN) if File.exist?(IOS_KEYCHAIN)
  File.delete(state[:installed_profile]) if state[:installed_profile] && File.exist?(state[:installed_profile])
end
```

Replace the entire `build_ios` lane with:

```ruby
desc "Build a signed iOS release IPA"
lane :build_ios do
  context = load_context
  credentials = load_credentials
  workspace = Dir[File.join(PROJECT_ROOT, "ios", "*.xcworkspace")].first
  UI.user_error!("No Xcode workspace found; run `expo prebuild` first.") if workspace.nil?

  bundle_id = context.dig("ios", "bundleIdentifier")
  state = nil
  begin
    state = setup_ios_signing(credentials)
    build_app(
      workspace: workspace,
      scheme: File.basename(workspace, ".xcworkspace"),
      export_method: "app-store",
      output_directory: File.join(PROJECT_ROOT, "build"),
      xcargs: %Q(CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM=#{state[:team]} ) +
              %Q(PROVISIONING_PROFILE_SPECIFIER="#{state[:profile_name]}" ) +
              %Q(CODE_SIGN_IDENTITY=#{state[:identity]}),
      export_options: {
        method: "app-store",
        signingStyle: "manual",
        teamID: state[:team],
        provisioningProfiles: { bundle_id => state[:profile_name] },
        signingCertificate: "Apple Distribution",
      },
    )
    UI.success("Built #{bundle_id} signed release IPA.")
  ensure
    teardown_ios_signing(state)
  end
end
```

- [ ] **Step 4: Byte-check the Ruby parses**

Run (from `demos/expo-hello-world`): `ruby -c fastlane/Fastfile`
Expected: `Syntax OK`.

- [ ] **Step 5: Put the original (macOS-importable) legacy certificate into App Manager**

The App Manager server is running with Task 1's fix, so it now accepts legacy p12 files. Replace the `appfront-sandbox` iOS certificate file with the **original** EAS legacy p12 (macOS `security import` accepts legacy p12; it rejects the earlier modern re-wrap). Find the credential id and upload the file:

```bash
BASE=http://localhost:8000/api/v1/demo-users-team/apps/appfront-sandbox
KEY=demo-api-key
SRC=/Users/igor/Projects/Appfront/mobile-next/eas-sandbox-credentials
CRED=$(curl -s -H "Authorization: Bearer $KEY" -H "Accept: application/json" "$BASE/credentials" \
  | php -r '$d=json_decode(file_get_contents("php://stdin"),true)["data"]; foreach($d as $c){ if($c["type"]==="ios_certificate"){ echo $c["id"]; break; } }')
echo "cert credential: $CRED"
curl -s -w "\nHTTP %{http_code}\n" -X POST "$BASE/credentials/$CRED/file" \
  -H "Authorization: Bearer $KEY" -H "Accept: application/json" \
  -F "file=@$SRC/app-store-dist-cert.p12;type=application/x-pkcs12;filename=distribution.p12"
```

Expected: `HTTP 200` (the legacy p12 now passes validation — this also confirms Task 1 end-to-end).

- [ ] **Step 6: Re-run prepare so the demo downloads the legacy certificate**

```bash
cd demos/expo-hello-world
rm -rf android ios
APP_MANAGER_APP=appfront-sandbox \
APP_MANAGER_ANDROID_IDENTIFIER=sandbox.appfront.ai APP_MANAGER_ANDROID_PROFILE_TYPE=production \
APP_MANAGER_IOS_IDENTIFIER=sandbox.appfront.ai APP_MANAGER_IOS_PROFILE_TYPE=appstore \
node scripts/prepare.mjs
```

Expected: prepare succeeds; `credentials/ios/distribution.p12` is the legacy file.

- [ ] **Step 7: Build and verify the signed IPA**

```bash
cd demos/expo-hello-world
bundle exec fastlane build platforms:"ios"
```

Expected: lane succeeds and prints `Built sandbox.appfront.ai signed release IPA.` Then verify:

```bash
cd demos/expo-hello-world
rm -rf /tmp/ipa_check && mkdir /tmp/ipa_check && (cd /tmp/ipa_check && unzip -q "$(ls ../../build/*.ipa 2>/dev/null || ls build/*.ipa)")
APP=$(ls -d /tmp/ipa_check/Payload/*.app)
codesign -dvvv "$APP" 2>&1 | grep -E "^Authority|^TeamIdentifier|^Identifier"
codesign --verify --strict --verbose=2 "$APP"
```

Expected: authority chain `Apple Distribution: Appfront A.I. LTD (LUVN6C8267)` → WWDR → Apple Root CA, `TeamIdentifier=LUVN6C8267`, and `valid on disk / satisfies its Designated Requirement`. Confirm the temp keychain and installed profile were cleaned up: `test ! -f demos/expo-hello-world/fastlane/build.keychain-db && echo "keychain cleaned"`.

- [ ] **Step 8: Update the README**

In `demos/expo-hello-world/README.md`, in the "Сборка и деплой (Fastlane)" section, add a note after the build commands:

```markdown
`build ios` подписывает IPA вручную: lane создаёт временный keychain, импортирует
скачанный distribution-сертификат, устанавливает provisioning profile и собирает с
manual signing, затем удаляет временный keychain и профиль. Цепочку доверия (WWDR,
Apple root) берёт из системного keychain. CocoaPods поставляется через `Gemfile`,
поэтому `pod install` работает под `bundle exec`.
```

- [ ] **Step 9: Commit**

```bash
git add demos/expo-hello-world/fastlane/Fastfile demos/expo-hello-world/Gemfile demos/expo-hello-world/README.md
git commit -m "feat(demo): sign iOS builds with App Manager credentials via Fastlane"
```

---

## Self-Review

- **Spec coverage:** A (validator fix) → Task 1; B (Fastfile signing) → Task 2 Steps 1-4,8; C (Gemfile) → Task 2 Steps 1-2; end-to-end verification incl. the legacy-p12 re-upload → Task 2 Steps 5-7; README → Task 2 Step 8. All covered.
- **Placeholders:** none — full PHP, Ruby, and shell provided.
- **Type consistency:** `setup_ios_signing` returns `{identity, profile_name, team, previous_keychains, installed_profile}`; `build_ios` reads `state[:identity]`/`[:profile_name]`/`[:team]`; `teardown_ios_signing` reads `[:previous_keychains]`/`[:installed_profile]` — consistent. `readableWithOpensslCli` name matches its call site in `validatePkcs12`.
- **Ordering note:** Task 2's verification depends on Task 1 being live on the running server (PHP picks up the edited rule on the next request; no rebuild needed).
