#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-assets-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-assets-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

# --- assets list ---
: >"$MOCK_LOG"
output=$("$CLI" --json assets list 2>&1)
assert_contains "$output" 'icon.original' 'assets list returns icon.original'
assert_contains "$output" 'splashscreen.original' 'assets list returns splashscreen.original'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/assets' 'assets list hits the assets endpoint'
pass 'assets list'

# --- assets list without --json renders the url map as an aligned table ---
# The endpoint answers with an object keyed by "<asset>.<variant>", so the generic
# table formatter cannot read it. Only --json was covered before, which hid that.
: >"$MOCK_LOG"
output=$("$CLI" assets list 2>&1)
assert_contains "$output" 'asset' 'assets list prints a header'
assert_contains "$output" 'icon.original' 'assets list prints the asset key'
assert_contains "$output" 'assets/icon/files/original' 'assets list prints the download url'
assert_contains "$output" 'icon.ios-icon-40' 'assets list prints generated variants'
case "$output" in
  *jq*error*) fail 'assets list must not emit jq errors' ;;
esac
pass 'assets list default output'

# --- assets show (preview) ---
: >"$MOCK_LOG"
output_file="$TEST_DIR/preview.png"
"$CLI" assets show --type icon -o "$output_file" --force 2>&1
[ -f "$output_file" ] || fail 'assets show writes to file'
assert_contains "$(cat "$output_file")" 'fake-asset-preview' 'assets show downloads preview'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/assets/icon' 'assets show hits the icon endpoint'
pass 'assets show'

# --- assets download original ---
: >"$MOCK_LOG"
output_file="$TEST_DIR/icon.png"
"$CLI" assets download --type icon --variant original -o "$output_file" --force 2>&1
[ -f "$output_file" ] || fail 'assets download writes to file'
assert_contains "$(cat "$output_file")" 'fake-asset-content' 'assets download gets content'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/assets/icon/files/original' 'assets download hits the files endpoint'
pass 'assets download original'

# --- assets download variant ---
: >"$MOCK_LOG"
output_file="$TEST_DIR/icon-40.png"
"$CLI" assets download --type icon --variant ios-icon-40 -o "$output_file" --force 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/assets/icon/files/ios-icon-40' 'assets download hits the variant endpoint'
pass 'assets download variant'

# --- assets upload ---
: >"$MOCK_LOG"
upload_source="$TEST_DIR/icon.png"
printf 'png-bytes' >"$upload_source"
output=$("$CLI" assets upload --type icon --file "$upload_source" 2>&1)
assert_contains "$output" 'Customer App' 'assets upload prints app name'
assert_contains "$(cat "$MOCK_LOG")" 'POST' 'assets upload posts'
assert_contains "$(cat "$MOCK_ARG_LOG")" 'file=@' 'assets upload sends a multipart file'
pass 'assets upload'

# --- assets upload splashscreen ---
: >"$MOCK_LOG"
upload_source="$TEST_DIR/splash.png"
printf 'splash-png' >"$upload_source"
output=$("$CLI" assets upload --type splashscreen --file "$upload_source" 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/assets/splashscreen' 'assets upload hits splashscreen endpoint'
pass 'assets upload splashscreen'

# --- assets delete ---
: >"$MOCK_LOG"
output=$("$CLI" assets delete --type icon 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'assets delete deletes'
assert_contains "$output" 'deleted' 'assets delete confirms'
pass 'assets delete'

# --- rejects invalid type ---
if "$CLI" assets show --type banner 2>&1; then
  fail 'assets show must reject invalid type'
fi
pass 'assets show rejects invalid type'

# --- dry run ---
dry=$("$CLI" --dry-run assets list 2>&1)
assert_contains "$dry" 'dry-run' 'assets list dry-run'
pass 'assets list dry-run'

# --- help ---
help=$("$CLI" assets --help 2>&1)
assert_contains "$help" 'icon' 'assets --help mentions icon'
pass 'assets --help'

printf '1..11\n'
