#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-apps-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() {
  case "$TEST_DIR" in
    */appmctl-apps.*) rm -rf -- "$TEST_DIR" ;;
  esac
}
trap cleanup EXIT

fail() { printf 'not ok - %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$1"; }
assert_contains() {
  case "$1" in *"$2"*) ;; *) fail "$3 (missing '$2')" ;; esac
}

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- apps list ---
: >"$MOCK_LOG"
output=$("$CLI" --json apps list 2>&1)
assert_contains "$output" 'customer-app' 'apps list returns app slugs'
assert_contains "$(cat "$MOCK_LOG")" '/apps' 'apps list hits correct endpoint'
pass 'apps list'

# --- apps get ---
: >"$MOCK_LOG"
output=$("$CLI" --json apps get customer-app 2>&1)
assert_contains "$output" 'customer-app' 'apps get returns app data'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app' 'apps get uses correct URL'
pass 'apps get'

# --- apps create ---
: >"$MOCK_LOG"
output=$("$CLI" --json apps create --name "New App" --slug new-app --description "A new app" 2>&1)
assert_contains "$output" 'new-app' 'apps create returns new app'
pass 'apps create'

# --- apps update ---
: >"$MOCK_LOG"
output=$("$CLI" apps update customer-app --name "Updated App" 2>&1)
assert_contains "$output" 'updated' 'apps update prints confirmation'
pass 'apps update'

# --- apps delete ---
: >"$MOCK_LOG"
output=$("$CLI" apps delete customer-app 2>&1)
assert_contains "$output" 'deleted' 'apps delete prints confirmation'
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'apps delete uses DELETE method'
pass 'apps delete'

# --- apps dry-run ---
dry=$("$CLI" --dry-run apps list 2>&1)
assert_contains "$dry" 'dry-run' 'apps list dry-run'
pass 'apps dry-run'

printf '1..6\n'
