#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-builds-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() {
  case "$TEST_DIR" in
    */appmctl-builds.*) rm -rf -- "$TEST_DIR" ;;
  esac
}
trap cleanup EXIT

fail() {
  printf 'not ok - %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'ok - %s\n' "$1"
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"
  case "$haystack" in
    *"$needle"*) ;;
    *) fail "$label (missing '$needle')" ;;
  esac
}

assert_not_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"
  case "$haystack" in
    *"$needle"*) fail "$label (unexpectedly contains '$needle')" ;;
  esac
}

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_BUILD_ID='bld-env'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- builds list ---
: >"$MOCK_LOG"
output=$("$CLI" --json builds list --status running 2>&1)
assert_contains "$output" 'bld-001' 'builds list returns data'
assert_contains "$(cat "$MOCK_LOG")" 'status=running' 'builds list passes status filter'
pass 'builds list with status filter'

# --- builds list dry-run ---
dry_output=$("$CLI" --dry-run builds list --app my-app 2>&1)
assert_contains "$dry_output" 'apps/my-app/builds' 'builds list dry-run uses correct path'
assert_not_contains "$dry_output" 'super-secret-token' 'builds list dry-run does not expose key'
pass 'builds list dry-run'

# --- builds get ---
: >"$MOCK_LOG"
output=$("$CLI" --json builds get --app customer-app bld-001 2>&1)
assert_contains "$output" 'bld-001' 'builds get returns build data'
assert_contains "$(cat "$MOCK_LOG")" 'builds/bld-001' 'builds get uses correct URL'
pass 'builds get'

# --- builds create ---
: >"$MOCK_LOG"
output=$("$CLI" --json builds create --app customer-app --platform android --profile production 2>&1)
assert_contains "$output" 'bld-003' 'builds create returns new build'
request_body=$(grep -o 'profile_type.*production' "$MOCK_LOG" || true)
[ -n "$request_body" ] || fail 'builds create sends profile in body'
assert_contains "$(cat "$MOCK_LOG")" '"platform": "android"' 'builds create sends the target platform'
pass 'builds create'

# --- builds create for Web default identifier resolution ---
: >"$MOCK_LOG"
output=$("$CLI" --json builds create --app customer-app --platform web --profile production 2>&1)
assert_contains "$output" 'bld-003' 'Web builds create returns new build'
assert_contains "$(cat "$MOCK_LOG")" '"platform": "web"' 'Web builds create sends the target platform'
pass 'builds create for Web'

# --- builds cancel ---
: >"$MOCK_LOG"
output=$("$CLI" builds cancel --app customer-app bld-001 2>&1)
assert_contains "$output" 'cancelled' 'builds cancel prints confirmation'
assert_contains "$(cat "$MOCK_LOG")" 'cancel' 'builds cancel hits cancel endpoint'
pass 'builds cancel'

# --- builds patch ---
: >"$MOCK_LOG"
output=$("$CLI" builds patch --app customer-app bld-001 --status completed 2>&1)
assert_contains "$output" 'completed' 'builds patch prints new status'
request_body=$(grep -o '"status":"completed"' "$MOCK_LOG" || true)
[ -n "$request_body" ] || fail 'builds patch sends status in body'
pass 'builds patch'

# --- manual build handoff to EAS ---
: >"$MOCK_LOG"
output=$("$CLI" builds patch --app customer-app bld-001 --expo-build-id eas-build-123 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$output" 'switched to EAS build eas-build-123' 'builds patch reports EAS handoff'
assert_contains "$log_line" '"expo_build_id":"eas-build-123"' 'builds patch sends the Expo build ID'
assert_not_contains "$log_line" '"status"' 'EAS handoff does not send a manual status'
pass 'builds patch hands a manual build off to EAS'

# --- build lifecycle aliases ---
: >"$MOCK_LOG"
output=$("$CLI" build start --app customer-app 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$output" 'updated to running' 'build start prints the running status'
assert_contains "$log_line" 'builds/bld-env' 'build start defaults to APP_MANAGER_BUILD_ID'
assert_contains "$log_line" '"status":"running"' 'build start sends running status'
pass 'build start alias'

: >"$MOCK_LOG"
output=$("$CLI" build completed --app customer-app bld-explicit 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$output" 'updated to completed' 'build completed prints the completed status'
assert_contains "$log_line" 'builds/bld-explicit' 'explicit build ID overrides APP_MANAGER_BUILD_ID'
assert_contains "$log_line" '"status":"completed"' 'build completed sends completed status'
pass 'build completed alias'

: >"$MOCK_LOG"
output=$("$CLI" build failed --app customer-app 2>&1)
assert_contains "$output" 'updated to failed' 'build failed prints the failed status'
assert_contains "$(cat "$MOCK_LOG")" '"status":"failed"' 'build failed sends failed status'
pass 'build failed alias'

: >"$MOCK_LOG"
output=$(APP_MANAGER_BUILD_ID='' "$CLI" build completed --new --app customer-app 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$output" 'bld-003' 'build completed --new prints the new build'
assert_contains "$log_line" $'POST\thttps://manager.example/api/v1/mobile-team/apps/customer-app/builds' 'build completed --new creates a build'
assert_contains "$log_line" '"status": "completed"' 'build completed --new sends completed status'
assert_contains "$log_line" '"profile_type": "production"' 'build completed --new defaults to production profile'
pass 'build completed --new alias'

: >"$MOCK_LOG"
output=$(APP_MANAGER_BUILD_ID='' "$CLI" build failed --new --profile preview 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$output" 'bld-003' 'build failed --new prints the new build'
assert_contains "$log_line" '"status": "failed"' 'build failed --new sends failed status'
assert_contains "$log_line" '"profile_type": "preview"' 'build failed --new accepts an explicit profile'
pass 'build failed --new alias'

build_help=$("$CLI" build --help 2>&1)
assert_contains "$build_help" 'APP_MANAGER_BUILD_ID' 'build help documents the default build ID'
assert_contains "$build_help" 'completed' 'build help lists lifecycle actions'
pass 'build lifecycle help'

set +e
missing_id_output=$(APP_MANAGER_BUILD_ID='' "$CLI" build start 2>&1)
missing_id_code=$?
set -e
[ "$missing_id_code" -ne 0 ] || fail 'build start should require a build ID or APP_MANAGER_BUILD_ID'
assert_contains "$missing_id_output" 'APP_MANAGER_BUILD_ID' 'missing alias build ID explains the environment fallback'
pass 'build lifecycle alias requires an ID'

# --- builds create with tags ---
: >"$MOCK_LOG"
output=$("$CLI" --json builds create --app customer-app --profile production \
  --tag 'ios:#3b82f6' --tag '1.0.0' 2>&1)
assert_contains "$output" 'bld-003' 'builds create with tags returns new build'
log_line=$(cat "$MOCK_LOG")
assert_contains "$log_line" 'tags' 'builds create sends tags in body'
assert_contains "$log_line" '"ios"' 'builds create sends tag name'
assert_contains "$log_line" '"#3b82f6"' 'builds create sends tag color'
assert_contains "$log_line" '"1.0.0"' 'builds create sends colorless tag'
pass 'builds create with tags'

# --- builds patch tags only (no status) ---
: >"$MOCK_LOG"
output=$("$CLI" builds patch --app customer-app bld-001 --tag 'android:#22c55e' 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$log_line" '"tags":[["android","#22c55e"]]' 'builds patch sends tags-only body'
assert_not_contains "$log_line" '"status"' 'builds patch omits status when not given'
pass 'builds patch with tags only'

# --- builds patch status and tags together ---
: >"$MOCK_LOG"
output=$("$CLI" builds patch --app customer-app bld-001 --status completed --tag 'web' 2>&1)
log_line=$(cat "$MOCK_LOG")
assert_contains "$log_line" '"status":"completed"' 'builds patch keeps status alongside tags'
assert_contains "$log_line" '"tags":[["web",null]]' 'builds patch sends colorless tag as null'
pass 'builds patch with status and tags'

# --- builds rejects a malformed tag color ---
set +e
bad_output=$("$CLI" builds patch --app customer-app bld-001 --tag 'ios:#blue' 2>&1)
bad_code=$?
set -e
[ "$bad_code" -ne 0 ] || fail 'builds patch should reject an invalid tag color'
assert_contains "$bad_output" 'hex' 'invalid tag color explains the expected format'
pass 'builds rejects invalid tag color'

# --- builds --help ---
help_output=$("$CLI" builds --help 2>&1)
assert_contains "$help_output" 'list' 'builds help lists actions'
assert_contains "$help_output" 'create' 'builds help mentions create'
pass 'builds --help'

# --- builds get default output ---
# BuildRecordResource has no "app" key, so a format referencing app.slug printed a
# blank row. Assert the fields that do exist, including tags.
output=$("$CLI" builds get bld-001 2>&1)
assert_contains "$output" 'manual' 'builds get prints the reporting mode'
assert_contains "$output" 'android, 1.4.0' 'builds get prints tag names'
assert_contains "$output" 'completed' 'builds get prints the status'
pass 'builds get default output'

printf '1..20\n'
