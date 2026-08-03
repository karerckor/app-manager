#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-pipelines-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-pipelines.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

# --- integrations list ---
: >"$MOCK_LOG"
output=$("$CLI" --json integrations list 2>&1)
assert_contains "$output" 'int-001' 'integrations list returns data'
pass 'integrations list'

# --- integrations get ---
output=$("$CLI" --json integrations get int-001 2>&1)
assert_contains "$output" 'int-001' 'integrations get returns data'
pass 'integrations get'

# --- integrations repos ---
output=$("$CLI" --json integrations repos --integration-id int-001 2>&1)
assert_contains "$output" 'repo-001' 'integrations repos returns repos'
pass 'integrations repos'

# --- integrations workflows ---
output=$("$CLI" --json integrations workflows --integration-id int-001 2>&1)
assert_contains "$output" 'wf-001' 'integrations workflows returns workflows'
pass 'integrations workflows'

# --- integrations refs ---
output=$("$CLI" --json integrations refs --integration-id int-001 2>&1)
assert_contains "$output" 'refs/heads/main' 'integrations refs returns refs'
pass 'integrations refs'

# --- pipelines list ---
output=$("$CLI" --json pipelines list --integration-id int-001 2>&1)
assert_contains "$output" 'pipe-001' 'pipelines list returns data'
pass 'pipelines list'

# --- pipelines get ---
output=$("$CLI" --json pipelines get --integration-id int-001 pipe-001 2>&1)
assert_contains "$output" 'pipe-001' 'pipelines get returns data'
pass 'pipelines get'

# --- pipelines create ---
output=$("$CLI" --json pipelines create --integration-id int-001 --name "New Pipeline" 2>&1)
assert_contains "$output" 'pipe-002' 'pipelines create returns new pipeline'
pass 'pipelines create'

# --- pipelines run ---
: >"$MOCK_LOG"
output=$("$CLI" pipelines run --integration-id int-001 pipe-001 2>&1)
assert_contains "$output" 'bld-004' 'pipelines run prints the created build'
assert_contains "$(cat "$MOCK_LOG")" 'runs' 'pipelines run hits runs endpoint'
pass 'pipelines run'

# --- pipelines inputs ---
output=$("$CLI" --json pipelines inputs --integration-id int-001 pipe-001 2>&1)
assert_contains "$output" 'branch' 'pipelines inputs returns inputs'
pass 'pipelines inputs'

# --- pipelines delete ---
output=$("$CLI" pipelines delete --integration-id int-001 pipe-001 2>&1)
assert_contains "$output" 'deleted' 'pipelines delete prints confirmation'
pass 'pipelines delete'

# --- pipelines default output ---
output=$("$CLI" pipelines list --integration-id int-001 2>&1)
assert_contains "$output" 'acme/customer-app' 'pipelines list prints the repository'
assert_contains "$output" 'Release' 'pipelines list prints the workflow'
pass 'pipelines list default output'

output=$("$CLI" pipelines get --integration-id int-001 pipe-001 2>&1)
assert_contains "$output" '.github/workflows/release.yml' 'pipelines get prints the workflow path'
assert_contains "$output" 'main' 'pipelines get prints the default ref'
pass 'pipelines get default output'

# --- pipelines run sends the required request_uuid and prints the build ---
# The API requires request_uuid on a run; without it every trigger answered 422.
: >"$MOCK_LOG"
output=$("$CLI" pipelines run --integration-id int-001 pipe-001 --inputs '{"ref":"main"}' 2>&1)
assert_contains "$output" 'bld-004' 'pipelines run prints the created build id'
assert_contains "$output" 'dispatched' 'pipelines run prints the dispatch status'
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'request_uuid' 'pipelines run sends request_uuid'
assert_contains "$log_contents" 'pipelines/pipe-001/runs' 'pipelines run posts to the runs endpoint'
pass 'pipelines run body and output'

# --- an explicit request uuid and ref are forwarded ---
dry=$("$CLI" --dry-run pipelines run --integration-id int-001 pipe-001 \
  --request-uuid 33333333-4444-4555-8666-777777777777 --ref release/1.4 2>&1)
assert_contains "$dry" '"request_uuid": "33333333-4444-4555-8666-777777777777"' 'run forwards the request uuid'
assert_contains "$dry" '"ref": "release/1.4"' 'run forwards the ref'
pass 'pipelines run idempotency key and ref'

printf '1..15\n'
