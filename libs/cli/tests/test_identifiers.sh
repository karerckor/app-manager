#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-identifiers-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-identifiers-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

# --- identifiers list ---
: >"$MOCK_LOG"
output=$("$CLI" identifiers list 2>&1)
assert_contains "$output" 'com.example.customer' 'identifiers list returns data'
assert_contains "$output" 'ios' 'identifiers list shows the platform'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/identifiers' 'identifiers list hits the identifiers endpoint'
pass 'identifiers list'

# --- identifiers list honours --per-page ---
: >"$MOCK_LOG"
"$CLI" identifiers list --per-page 5 >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'per_page=5' 'identifiers list sends per_page'
pass 'identifiers list per-page'

# --- identifiers show ---
: >"$MOCK_LOG"
output=$("$CLI" identifiers show ident-001 2>&1)
assert_contains "$output" 'com.example.customer' 'identifiers show prints the identifier'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/identifiers/ident-001' 'identifiers show hits the single identifier endpoint'
pass 'identifiers show'

# --- identifiers create ---
: >"$MOCK_LOG"
output=$("$CLI" identifiers create --platform android --identifier com.example.customer \
  --name 'Customer App' --version 1.4.0 --version-code 42 2>&1)
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'POST' 'identifiers create posts'
assert_contains "$log_contents" 'apps/customer-app/identifiers' 'identifiers create hits the collection endpoint'
assert_contains "$output" 'com.example.customer' 'identifiers create prints the created identifier'
pass 'identifiers create'

# --- identifiers create sends a well-formed body ---
dry=$("$CLI" --dry-run identifiers create --platform android --identifier com.example.customer \
  --name 'Customer App' --version 1.4.0 --version-code 42 --default 2>&1)
assert_contains "$dry" '"platform": "android"' 'create body carries the platform'
assert_contains "$dry" '"identifier": "com.example.customer"' 'create body carries the identifier'
assert_contains "$dry" '"version_code": 42' 'create body sends version_code as a number'
assert_contains "$dry" '"is_default": true' 'create body sends the default flag as a boolean'
pass 'identifiers create body'

# --- identifiers update ---
: >"$MOCK_LOG"
"$CLI" identifiers update ident-001 --version 1.5.0 >/dev/null 2>&1
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'PATCH' 'identifiers update patches'
assert_contains "$log_contents" 'identifiers/ident-001' 'identifiers update targets the identifier'
pass 'identifiers update'

dry=$("$CLI" --dry-run identifiers update ident-001 --no-default 2>&1)
assert_contains "$dry" '"is_default": false' 'update body can clear the default flag'

# --- identifiers delete ---
: >"$MOCK_LOG"
output=$("$CLI" identifiers delete ident-001 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'identifiers delete deletes'
assert_contains "$output" 'deleted' 'identifiers delete confirms'
pass 'identifiers delete'

# --- platform validation ---
if "$CLI" identifiers create --platform windows --identifier com.example.app >/dev/null 2>&1; then
  fail 'identifiers create must reject an unknown platform'
fi
pass 'identifiers create rejects an unknown platform'

# --- required flags ---
if "$CLI" identifiers create --platform ios >/dev/null 2>&1; then
  fail 'identifiers create must require --identifier'
fi
if "$CLI" identifiers show >/dev/null 2>&1; then
  fail 'identifiers show must require an id'
fi
if "$CLI" identifiers update ident-001 >/dev/null 2>&1; then
  fail 'identifiers update must require at least one field'
fi
pass 'identifiers argument validation'

# --- dry run ---
dry=$("$CLI" --dry-run identifiers list 2>&1)
assert_contains "$dry" 'dry-run' 'identifiers list dry-run'
assert_contains "$dry" 'apps/customer-app/identifiers' 'identifiers list dry-run shows the url'
pass 'identifiers list dry-run'

# --- help ---
help=$("$CLI" identifiers --help 2>&1)
assert_contains "$help" 'identifiers <action>' 'identifiers help shows usage'
assert_contains "$help" 'version-code' 'identifiers help documents version-code'
pass 'identifiers --help'

printf '1..11\n'
