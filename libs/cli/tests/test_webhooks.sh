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
assert_contains "$output" 'enabled' 'webhooks get includes the enabled field'
pass 'webhooks get'

# --- webhooks create ---
output=$("$CLI" --json webhooks create --name 'Release notifier' --url https://example.com/hook --events '["build.completed"]' 2>&1)
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

# --- default output must map to fields the API actually returns ---
# WebhookEndpointResource exposes subscribed_events and enabled, and
# WebhookDeliveryResource exposes event_type and response_status. The old formats
# asked for events/active/status_code, which printed blank columns.
output=$("$CLI" webhooks list 2>&1)
assert_contains "$output" 'build.completed, build.failed' 'webhooks list prints subscribed events'
assert_contains "$output" 'true' 'webhooks list prints the enabled flag'
pass 'webhooks list default output'

output=$("$CLI" webhooks get wh-001 2>&1)
assert_contains "$output" 'build.completed, build.failed' 'webhooks get prints subscribed events'
assert_contains "$output" 'Release notifier' 'webhooks get prints the integration name'
pass 'webhooks get default output'

output=$("$CLI" webhooks deliveries wh-001 2>&1)
assert_contains "$output" 'build.completed' 'webhooks deliveries prints the event type'
assert_contains "$output" '200' 'webhooks deliveries prints the response status'
assert_contains "$output" 'delivered' 'webhooks deliveries prints the delivery status'
pass 'webhooks deliveries default output'

# --- create sends every field the API requires ---
# The API requires name, url and at least one event. The CLI used to send only url
# and an optional events array, so every create answered 422.
if "$CLI" webhooks create --url https://example.com/hook --events '["build.completed"]' >/dev/null 2>&1; then
  fail 'webhooks create must require --name'
fi
if "$CLI" webhooks create --name 'Release notifier' --events '["build.completed"]' >/dev/null 2>&1; then
  fail 'webhooks create must require --url'
fi
if "$CLI" webhooks create --name 'Release notifier' --url https://example.com/hook >/dev/null 2>&1; then
  fail 'webhooks create must require --events'
fi
dry=$("$CLI" --dry-run webhooks create --name 'Release notifier' \
  --url https://example.com/hook --events '["build.created","build.completed"]' 2>&1)
assert_contains "$dry" '"name": "Release notifier"' 'create sends the name'
assert_contains "$dry" '"url": "https://example.com/hook"' 'create sends the url'
assert_contains "$dry" '"build.created"' 'create sends the events array'
pass 'webhooks create required fields'

# --- filters use the shape the API validates ---
# --app used to be sent as "app_filter", a key the API does not accept, so the
# filter was silently dropped. It now resolves the slug to an id.
: >"$MOCK_LOG"
output=$("$CLI" webhooks create --name 'Android releases' \
  --url https://example.com/android --events '["build.completed"]' \
  --app customer-app --build-status completed --tag android 2>&1)
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'apps/customer-app' 'create resolves the app slug'
assert_contains "$log_contents" 'mobile_app_ids' 'create sends filters.mobile_app_ids'
assert_contains "$log_contents" '019fc789-478f-71a0-9177-0ed28c77cff2' 'create sends the resolved app id'
assert_contains "$log_contents" 'build_statuses' 'create sends filters.build_statuses'
assert_contains "$log_contents" '"android"' 'create sends filters.tags'
case "$log_contents" in
  *app_filter*) fail 'create must not send the app_filter key the API ignores' ;;
esac
pass 'webhooks create filters'

# --- update accepts a name and replaces filters ---
dry=$("$CLI" --dry-run webhooks update wh-001 --name 'Renamed notifier' --build-status failed 2>&1)
assert_contains "$dry" 'PATCH' 'update patches'
assert_contains "$dry" '"name": "Renamed notifier"' 'update sends the name'
assert_contains "$dry" '"build_statuses"' 'update sends filters'
pass 'webhooks update name and filters'

printf '1..15\n'
