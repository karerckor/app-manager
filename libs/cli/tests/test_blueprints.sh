#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-blueprints-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() {
  case "$TEST_DIR" in
    */appmctl-blueprints-test.*) rm -rf -- "$TEST_DIR" ;;
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

: >"$MOCK_LOG"
output=$("$CLI" blueprints list 2>&1)
assert_contains "$output" 'Production app' 'blueprints list formats blueprint rows'
assert_contains "$(cat "$MOCK_LOG")" $'GET\thttps://manager.example/api/v1/mobile-team/blueprints' 'blueprints list uses the collection endpoint'
pass 'blueprints list'

: >"$MOCK_LOG"
output=$("$CLI" --json blueprints get blueprint-001 2>&1)
assert_contains "$output" 'associated-domains.json' 'blueprints get returns the complete resource'
pass 'blueprints get'

: >"$MOCK_LOG"
output=$("$CLI" --json blueprints create --name 'Web app' \
  --json-schema '{"type":"object"}' --required-files '[]' --platforms '["web"]' 2>&1)
assert_contains "$output" 'blueprint-002' 'blueprints create returns the created blueprint'
request=$(cat "$MOCK_LOG")
assert_contains "$request" '"json_schema": {' 'blueprints create sends the JSON schema'
assert_contains "$request" '"type": "object"' 'blueprints create preserves the schema object'
assert_contains "$request" '"platforms": [' 'blueprints create sends platforms'
pass 'blueprints create'

: >"$MOCK_LOG"
output=$("$CLI" blueprints update blueprint-001 --name 'Updated blueprint' --required-files '[]' 2>&1)
assert_contains "$output" 'Updated blueprint' 'blueprints update prints the updated resource'
assert_contains "$(cat "$MOCK_LOG")" $'PATCH\thttps://manager.example/api/v1/mobile-team/blueprints/blueprint-001' 'blueprints update uses PATCH'
pass 'blueprints update'

: >"$MOCK_LOG"
output=$("$CLI" blueprints evaluate blueprint-001 2>&1)
assert_contains "$output" '3' 'blueprints evaluate prints the evaluated app count'
assert_contains "$(cat "$MOCK_LOG")" 'blueprints/blueprint-001/evaluate' 'blueprints evaluate uses the action endpoint'
pass 'blueprints evaluate'

: >"$MOCK_LOG"
output=$("$CLI" blueprints hooks list blueprint-001 2>&1)
assert_contains "$output" 'Flag incomplete apps' 'hook list formats hook rows'
pass 'blueprint hooks list'

: >"$MOCK_LOG"
output=$("$CLI" --json blueprints hooks get blueprint-001 hook-001 2>&1)
assert_contains "$output" 'blueprint_mismatch' 'hook get returns the condition'
pass 'blueprint hooks get'

: >"$MOCK_LOG"
actions='[{"type":"remove_tag","tags":[["blueprint-incomplete"]]},{"type":"add_tag","tags":[["ready","#10b981","pulse"]]}]'
output=$("$CLI" --json blueprints hooks create blueprint-001 --name 'Mark ready' \
  --condition blueprint_matches --actions "$actions" 2>&1)
assert_contains "$output" 'hook-002' 'hook create returns the created hook'
request=$(cat "$MOCK_LOG")
assert_contains "$request" '"type": "remove_tag"' 'hook create preserves the remove action'
assert_contains "$request" '"type": "add_tag"' 'hook create preserves the add action'
pass 'blueprint hooks create with ordered actions'

: >"$MOCK_LOG"
output=$("$CLI" blueprints hooks update blueprint-001 hook-001 --name 'Updated hook' --enabled false 2>&1)
assert_contains "$output" 'Updated hook' 'hook update returns the updated hook'
assert_contains "$(cat "$MOCK_LOG")" '"enabled": false' 'hook update sends a JSON boolean'
pass 'blueprint hooks update'

: >"$MOCK_LOG"
output=$("$CLI" blueprints hooks delete blueprint-001 hook-001 2>&1)
assert_contains "$output" 'deleted' 'hook delete confirms deletion'
assert_contains "$(cat "$MOCK_LOG")" 'DELETE' 'hook delete uses DELETE'
pass 'blueprint hooks delete'

: >"$MOCK_LOG"
output=$("$CLI" blueprints delete blueprint-001 2>&1)
assert_contains "$output" 'deleted' 'blueprint delete confirms deletion'
pass 'blueprints delete'

if "$CLI" blueprints create --name Bad --platforms 'not-json' >/dev/null 2>&1; then
  fail 'blueprints create accepted invalid platforms JSON'
fi
pass 'blueprint JSON options are validated locally'

printf '1..12\n'
