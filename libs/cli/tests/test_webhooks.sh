#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-webhooks-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-webhooks.*) rm -rf -- "$TEST_DIR" ;; esac; }
trap cleanup EXIT

fail() { printf 'not ok - %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$1"; }
assert_contains() { case "$1" in *"$2"*) ;; *) fail "$3 (missing '$2')" ;; esac; }

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- webhooks list ---
: >"$MOCK_LOG"
output=$("$CLI" --json webhooks list 2>&1)
assert_contains "$output" 'wh-001' 'webhooks list returns data'
assert_contains "$output" 'example.com' 'webhooks list includes URL'
pass 'webhooks list'

# --- webhooks get ---
output=$("$CLI" --json webhooks get wh-001 2>&1)
assert_contains "$output" 'wh-001' 'webhooks get returns data'
assert_contains "$output" 'active' 'webhooks get includes active field'
pass 'webhooks get'

# --- webhooks create ---
output=$("$CLI" --json webhooks create --url https://example.com/hook --events '["build.completed"]' 2>&1)
assert_contains "$output" 'wh-002' 'webhooks create returns new webhook'
pass 'webhooks create'

# --- webhooks update ---
output=$("$CLI" webhooks update wh-001 --url https://new-url.com/hook 2>&1)
assert_contains "$output" 'updated' 'webhooks update prints confirmation'
pass 'webhooks update'

# --- webhooks delete ---
output=$("$CLI" webhooks delete wh-001 2>&1)
assert_contains "$output" 'deleted' 'webhooks delete prints confirmation'
pass 'webhooks delete'

# --- webhooks test ---
: >"$MOCK_LOG"
output=$("$CLI" webhooks test wh-001 2>&1)
assert_contains "$output" 'Test delivery sent' 'webhooks test prints confirmation'
assert_contains "$(cat "$MOCK_LOG")" 'test' 'webhooks test hits test endpoint'
pass 'webhooks test'

# --- webhooks rotate ---
output=$("$CLI" webhooks rotate wh-001 2>&1)
assert_contains "$output" 'rotated' 'webhooks rotate prints confirmation'
pass 'webhooks rotate'

# --- webhooks deliveries ---
output=$("$CLI" --json webhooks deliveries wh-001 2>&1)
assert_contains "$output" 'del-001' 'webhooks deliveries returns data'
pass 'webhooks deliveries'

# --- webhooks redeliver ---
output=$("$CLI" webhooks redeliver --webhook-id wh-001 del-001 2>&1)
assert_contains "$output" 'redelivered' 'webhooks redeliver prints confirmation'
pass 'webhooks redeliver'

printf '1..9\n'
