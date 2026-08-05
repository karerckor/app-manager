#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-creds-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-creds.*) rm -rf -- "$TEST_DIR" ;; esac; }
trap cleanup EXIT

fail() { printf 'not ok - %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$1"; }
assert_contains() { case "$1" in *"$2"*) ;; *) fail "$3 (missing '$2')" ;; esac; }

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- credentials list ---
: >"$MOCK_LOG"
output=$("$CLI" --json credentials list 2>&1)
assert_contains "$output" 'cred-001' 'credentials list returns data'
assert_contains "$(cat "$MOCK_LOG")" 'credentials' 'credentials list hits correct endpoint'
pass 'credentials list'

# --- credentials get ---
output=$("$CLI" --json credentials get cred-001 2>&1)
assert_contains "$output" 'cred-001' 'credentials get returns data'
pass 'credentials get'

# --- default (non-JSON) output must map to fields the API actually returns ---
# CredentialResource exposes "type" and nested "profiles"; it has no
# credential_type.slug and no build_profile. Only --json was covered before, so
# the mismatch printed blank columns unnoticed.
output=$("$CLI" credentials list 2>&1)
assert_contains "$output" 'android_keystore' 'credentials list prints the type'
assert_contains "$output" 'Production Keystore' 'credentials list prints the name'
assert_contains "$output" 'ios_certificate' 'credentials list prints every row'
pass 'credentials list default output'

output=$("$CLI" credentials get cred-001 2>&1)
assert_contains "$output" 'android_keystore' 'credentials get prints the type'
assert_contains "$output" 'production' 'credentials get prints the attached profile'
assert_contains "$output" 'production.keystore' 'credentials get prints the attached file'
pass 'credentials get default output'

# --- credentials create ---
output=$("$CLI" --json credentials create --type android_keystore --profile production 2>&1)
assert_contains "$output" 'cred-001' 'credentials create returns data'
pass 'credentials create'

# --- credential secrets from file stay out of plans and curl argv ---
credential_data="$TEST_DIR/credential-data.json"
printf '{"store_password":"never-print-me","key_alias":"release","key_password":"never-print-me"}' >"$credential_data"
dry=$("$CLI" --dry-run credentials create --type android_keystore --profile production --data-file "$credential_data" 2>&1)
case "$dry" in
  *never-print-me*) fail 'credentials dry-run exposed credential secrets' ;;
esac
assert_contains "$dry" 'redacted' 'credentials dry-run describes a redacted body'
: >"$MOCK_ARG_LOG"
"$CLI" --json credentials create --type android_keystore --profile production --data-file "$credential_data" >/dev/null
case "$(cat "$MOCK_ARG_LOG")" in
  *never-print-me*) fail 'credential secret was exposed in curl process arguments' ;;
esac
pass 'credential data file keeps secrets out of dry-run and curl argv'

# --- credentials delete ---
output=$("$CLI" credentials delete cred-001 2>&1)
assert_contains "$output" 'deleted' 'credentials delete prints confirmation'
pass 'credentials delete'

# --- credentials types ---
output=$("$CLI" --json credentials types 2>&1)
assert_contains "$output" 'android_keystore' 'credentials types returns types'
pass 'credentials types'

# --- credentials dry-run ---
dry=$("$CLI" --dry-run credentials list 2>&1)
assert_contains "$dry" 'dry-run' 'credentials list dry-run'
pass 'credentials dry-run'

# --- credentials verify ---
# The platform never resolves an external vault itself: whoever can reach the
# vault reports the verdict. These tests pin that the CLI transmits a verdict
# and refuses the combinations the API rejects, before spending a request.
expect_failure() {
  local description="$1"
  shift
  set +e
  verify_output=$("$@" 2>&1)
  verify_status=$?
  set -e
  [ "$verify_status" -ne 0 ] || fail "$description (command succeeded)"
}

: >"$MOCK_LOG"
output=$("$CLI" credentials verify cred-001 --status verified 2>&1)
request=$(cat "$MOCK_LOG")
assert_contains "$request" 'credentials/cred-001/verification' 'verify posts to the verification endpoint'
assert_contains "$request" 'POST' 'verify uses POST'
assert_contains "$request" '"status":"verified"' 'verify sends the reported status'
assert_contains "$output" 'verified' 'verify reports the recorded status'
pass 'credentials verify reports a successful verification'

: >"$MOCK_LOG"
"$CLI" credentials verify cred-001 --status failed --error 'secret not found in vault' >/dev/null
request=$(cat "$MOCK_LOG")
assert_contains "$request" '"status":"failed"' 'verify sends a failed status'
assert_contains "$request" 'secret not found in vault' 'verify sends the failure reason'
pass 'credentials verify reports a failure with its reason'

: >"$MOCK_LOG"
"$CLI" credentials verify cred-001 --status verified \
  --expires-at 2027-03-01 --metadata '{"region":"eu-central-1"}' >/dev/null
request=$(cat "$MOCK_LOG")
assert_contains "$request" '"expires_at":"2027-03-01"' 'verify forwards a discovered expiry'
assert_contains "$request" '"region":"eu-central-1"' 'verify forwards vault metadata'
pass 'credentials verify forwards expiry and metadata'

# The API rejects each of these with 422. Catching them locally keeps a CI job
# from burning a request to learn it passed contradictory flags.
: >"$MOCK_LOG"
expect_failure 'verify accepted a failure with no reason' \
  "$CLI" credentials verify cred-001 --status failed
assert_contains "$verify_output" '--error' 'verify explains that a failure needs a reason'

expect_failure 'verify accepted an error on a successful verification' \
  "$CLI" credentials verify cred-001 --status verified --error 'nothing went wrong'

expect_failure 'verify accepted an expiry on a failed verification' \
  "$CLI" credentials verify cred-001 --status failed --error 'gone' --expires-at 2027-03-01

expect_failure 'verify accepted an unreportable status' \
  "$CLI" credentials verify cred-001 --status unverified
assert_contains "$verify_output" 'verified' 'verify names the statuses it accepts'

expect_failure 'verify accepted a missing status' \
  "$CLI" credentials verify cred-001

expect_failure 'verify accepted a missing credential ID' \
  "$CLI" credentials verify --status verified

expect_failure 'verify accepted metadata that is not JSON' \
  "$CLI" credentials verify cred-001 --status verified --metadata 'region=eu'

expect_failure 'verify accepted metadata that is not an object' \
  "$CLI" credentials verify cred-001 --status verified --metadata '["eu-central-1"]'

[ ! -s "$MOCK_LOG" ] || fail 'verify sent a request despite invalid arguments'
pass 'credentials verify rejects contradictory arguments without a request'

: >"$MOCK_LOG"
dry=$("$CLI" --dry-run credentials verify cred-001 --status failed --error 'no such secret' 2>&1)
assert_contains "$dry" 'dry-run: no request sent' 'verify dry-run sends nothing'
assert_contains "$dry" 'method: POST' 'verify dry-run names the method'
assert_contains "$dry" 'credentials/cred-001/verification' 'verify dry-run names the URL'
assert_contains "$dry" '"status":"failed"' 'verify dry-run shows the body'
[ ! -s "$MOCK_LOG" ] || fail 'verify dry-run made a request'
pass 'credentials verify dry-run'

# --- verify is discoverable ---
output=$("$CLI" credentials --help 2>&1)
assert_contains "$output" 'verify' 'credentials help lists the verify action'
pass 'credentials help lists verify'

printf '1..15\n'
