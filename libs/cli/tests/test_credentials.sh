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

printf '1..7\n'
