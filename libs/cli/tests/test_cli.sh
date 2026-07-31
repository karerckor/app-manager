#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() {
  case "$TEST_DIR" in
    */appmctl-test.*) rm -rf -- "$TEST_DIR" ;;
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

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

dry_output=$(
  APP_MANAGER_CURL_BIN=/does/not-exist "$CLI" --dry-run request POST apps --data '{"name":"Demo"}'
)
assert_contains "$dry_output" 'https://manager.example/api/v1/mobile-team/apps' 'dry-run normalizes the API URL'
assert_contains "$dry_output" 'Authorization: Bearer [REDACTED]' 'dry-run redacts authorization'
case "$dry_output" in
  *super-secret-token*) fail 'dry-run exposed the API key' ;;
esac
pass 'dry-run is safe and does not require curl'

response_file="$TEST_DIR/response.json"
body_file="$TEST_DIR/request.json"
printf '{"name":"From file"}' >"$body_file"
"$CLI" request POST apps --data-file "$body_file" --json --output "$response_file"
jq -e '.data' "$response_file" >/dev/null 2>&1 || fail 'JSON response was not written'
assert_contains "$(cat "$MOCK_LOG")" 'https://manager.example/api/v1/mobile-team/apps' 'request uses the team API root'
assert_contains "$(cat "$MOCK_LOG")" 'From file' 'request sends a body read from a file'
case "$(cat "$MOCK_ARG_LOG")" in
  *super-secret-token*) fail 'API key was exposed in curl process arguments' ;;
esac
pass 'request supports file input and JSON file output'

: >"$MOCK_LOG"
printf '{"name":"stdin"}' | "$CLI" request PATCH apps/customer-app --data-file - >/dev/null
assert_contains "$(cat "$MOCK_LOG")" '"name":"stdin"' 'request reads its body from stdin'
pass 'request supports stdin input'

: >"$MOCK_LOG"
upload_file="$TEST_DIR/icon.png"
printf 'fake image' >"$upload_file"
"$CLI" request POST apps/customer-app/assets/icon --form 'label=icon=primary' --file "file=$upload_file" >/dev/null
multipart_request=$(cat "$MOCK_LOG")
assert_contains "$multipart_request" 'multipart:label=icon=primary' 'multipart preserves equals signs in values'
assert_contains "$multipart_request" "multipart:file=@$upload_file" 'multipart sends a file field'
pass 'request supports multipart fields and files'

: >"$MOCK_LOG"
set +e
"$CLI" stream --build-id build-123 --step Compile --command sh -c 'printf "compile output\n"; exit 7'
stream_status=$?
set -e
[ "$stream_status" -eq 7 ] || fail "stream did not preserve command exit status (got $stream_status)"
stream_requests=$(cat "$MOCK_LOG")
assert_contains "$stream_requests" '--- step: Compile ---' 'stream sends a step start marker'
assert_contains "$stream_requests" 'compile output' 'stream uploads command output'
assert_contains "$stream_requests" 'finished (exit 7)' 'stream sends a step finish marker'
pass 'stream uploads output and preserves command exit status'

call_count=$(wc -l <"$MOCK_LOG" | tr -d '[:space:]')
[ "$call_count" -ge 3 ] || fail 'stream did not make the expected log requests'
pass 'stream sends markers and command output as separate chunks'

: >"$MOCK_LOG"
"$CLI" stream --build-id build-123 --manage-status --chunk-bytes 4 --command printf 'abcdefghij\n' >/dev/null
managed_requests=$(cat "$MOCK_LOG")
assert_contains "$managed_requests" '"status":"running"' 'managed stream marks the build running'
assert_contains "$managed_requests" '"status":"completed"' 'managed stream completes a successful build'
log_call_count=$(grep -c $'^POST\thttps://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-123/logs\t' "$MOCK_LOG")
[ "$log_call_count" -eq 3 ] || fail "stream did not honor the chunk size (got $log_call_count log calls)"
pass 'stream manages build status and honors chunk size'

printf '1..7\n'
