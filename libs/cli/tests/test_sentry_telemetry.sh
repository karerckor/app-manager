#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-sentry-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"
cleanup() { case "$TEST_DIR" in */appmctl-sentry-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
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

printf '%s' '{"name":"Customer relay","routing_method":"query","event_source":null,"app_key":"app_slug","identifier_key":"app_identifier","routes":[{"mobile_app_id":"019fc789-478f-71a0-9177-0ed28c77cff2"}],"forward_enabled":false,"upstream_dsn":""}' >"$TEST_DIR/sentry.json"

output=$("$CLI" --json sentry list 2>&1); assert_contains "$output" 'dsn-001' 'sentry list returns DSNs'; pass 'sentry list'
output=$("$CLI" --json sentry get dsn-001 2>&1); assert_contains "$output" 'Customer relay' 'sentry get returns configuration'; pass 'sentry get'
output=$("$CLI" --json sentry create --config "$TEST_DIR/sentry.json" 2>&1); assert_contains "$output" 'dsn-001' 'sentry create returns DSN'; pass 'sentry create'
dry=$("$CLI" --dry-run sentry update dsn-001 --config "$TEST_DIR/sentry.json" 2>&1); assert_contains "$dry" 'PATCH' 'sentry update uses PATCH'; assert_contains "$dry" 'routing_method' 'sentry update sends config'; pass 'sentry update'
output=$("$CLI" sentry delete dsn-001 2>&1); assert_contains "$output" 'deleted' 'sentry delete confirms'; pass 'sentry delete'

output=$("$CLI" --json --app customer-app telemetry issues --environment production 2>&1); assert_contains "$output" 'issue-001' 'issues list returns grouped issues'; pass 'telemetry issues'
output=$("$CLI" --json --app customer-app telemetry issue issue-001 --event evt-001 2>&1); assert_contains "$output" 'buyer-42' 'issue detail returns selected payload'; pass 'telemetry issue'
output=$("$CLI" --json --app customer-app telemetry traces 2>&1); assert_contains "$output" 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' 'traces list returns trace IDs'; pass 'telemetry traces'
output=$("$CLI" --json --app customer-app telemetry trace aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa 2>&1); assert_contains "$output" 'events_count' 'trace detail returns timeline'; pass 'telemetry trace'

assert_contains "$(cat "$MOCK_LOG")" 'environment=production' 'telemetry forwards filters'
printf '1..9\n'
