#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-files-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-files-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

# --- files list ---
: >"$MOCK_LOG"
output=$("$CLI" --json files list 2>&1)
assert_contains "$output" 'release-notes.pdf' 'files list returns data'
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/files' 'files list hits the files endpoint'
pass 'files list'

# --- files get (download) ---
: >"$MOCK_LOG"
output_file="$TEST_DIR/downloaded.pdf"
"$CLI" files get --name release-notes.pdf -o "$output_file" --force 2>&1
[ -f "$output_file" ] || fail 'files get writes to file'
assert_contains "$(cat "$output_file")" 'fake-file-content' 'files get downloads content'
assert_contains "$(cat "$MOCK_LOG")" 'files/release-notes.pdf/content' 'files get hits the content endpoint'
pass 'files get'

# --- files upload ---
: >"$MOCK_LOG"
upload_source="$TEST_DIR/notes.pdf"
printf 'pdf-bytes' >"$upload_source"
output=$("$CLI" files upload --file "$upload_source" --metadata '{"channel":"beta"}' 2>&1)
assert_contains "$output" 'release-notes.pdf' 'files upload prints the created file'
assert_contains "$(cat "$MOCK_LOG")" 'POST' 'files upload posts'
assert_contains "$(cat "$MOCK_ARG_LOG")" 'file=@' 'files upload sends a multipart file'
pass 'files upload'

# --- files update ---
: >"$MOCK_LOG"
output=$("$CLI" files update --name release-notes.pdf --metadata '{"channel":"stable"}' 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'PATCH' 'files update patches'
assert_contains "$output" 'release-notes.pdf' 'files update prints the file'
pass 'files update'

# --- files delete ---
: >"$MOCK_LOG"
output=$("$CLI" files delete --name release-notes.pdf 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'files delete deletes'
assert_contains "$output" 'deleted' 'files delete confirms'
pass 'files delete'

# --- name encoding ---
: >"$MOCK_LOG"
"$CLI" files get --name 'report (1).pdf' -o "$TEST_DIR/report.pdf" --force 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'report%20%281%29.pdf/content' 'files get percent-encodes the name'
pass 'files get encodes names'

# --- rejects path traversal ---
if "$CLI" files get --name '../secrets' -o "$TEST_DIR/x" --force >/dev/null 2>&1; then
  fail 'files get must reject names with slashes'
fi
pass 'files get rejects slashes'

# --- dry run ---
dry=$("$CLI" --dry-run files list 2>&1)
assert_contains "$dry" 'dry-run' 'files list dry-run'
pass 'files list dry-run'

printf '1..8\n'
