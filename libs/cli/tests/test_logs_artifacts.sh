#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-logs-artifacts-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-logs-artifacts.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

# --- logs get ---
: >"$MOCK_LOG"
output=$("$CLI" logs get --build-id bld-001 2>&1)
assert_contains "$output" 'Build log' 'logs get returns log content'
assert_contains "$(cat "$MOCK_LOG")" 'logs' 'logs get hits logs endpoint'
pass 'logs get'

# --- logs get dry-run ---
dry=$("$CLI" --dry-run logs get --build-id bld-001 2>&1)
assert_contains "$dry" 'dry-run' 'logs get dry-run'
pass 'logs get dry-run'

# --- artifacts list ---
: >"$MOCK_LOG"
output=$("$CLI" --json artifacts list --build-id bld-001 2>&1)
assert_contains "$output" 'art-001' 'artifacts list returns data'
assert_contains "$output" 'app-release.aab' 'artifacts list includes name'
pass 'artifacts list'

# --- artifacts get (download) ---
output_file="$TEST_DIR/downloaded-artifact"
"$CLI" artifacts get --build-id bld-001 art-001 -o "$output_file" --force 2>&1
[ -f "$output_file" ] || fail 'artifacts get writes to file'
assert_contains "$(cat "$output_file")" 'fake-file-content' 'artifacts get downloads content'
pass 'artifacts get'

# --- artifacts list dry-run ---
dry=$("$CLI" --dry-run artifacts list --build-id bld-001 2>&1)
assert_contains "$dry" 'dry-run' 'artifacts list dry-run'
pass 'artifacts list dry-run'

printf '1..5\n'
