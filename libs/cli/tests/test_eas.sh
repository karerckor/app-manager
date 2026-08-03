#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-eas-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
CLI="$CLI_DIR/bin/appmctl"
MOCK_CURL="$CLI_DIR/tests/fixtures/mock_curl.sh"
MOCK_LOG="$TEST_DIR/curl.log"
MOCK_ARG_LOG="$TEST_DIR/curl-args.log"

cleanup() { case "$TEST_DIR" in */appmctl-eas-test.*) rm -rf -- "$TEST_DIR" ;; esac; }
trap cleanup EXIT

fail() { printf 'not ok - %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$1"; }
assert_contains() { case "$1" in *"$2"*) ;; *) fail "$3 (missing '$2')" ;; esac; }
refute_contains() { case "$1" in *"$2"*) fail "$3 (found '$2')" ;; *) ;; esac; }

export APP_MANAGER_API_KEY='super-secret-token'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example/api/v1/'
export APP_MANAGER_CURL_BIN="$MOCK_CURL"
export APPM_TEST_CURL_LOG="$MOCK_LOG"
export APPM_TEST_CURL_ARG_LOG="$MOCK_ARG_LOG"

# --- eas connect ---
: >"$MOCK_LOG"
output=$("$CLI" eas connect --token expo-token-value --account acme 2>&1)
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'POST' 'eas connect posts'
assert_contains "$log_contents" 'integrations/expo' 'eas connect hits the expo integration endpoint'
assert_contains "$output" 'acme' 'eas connect prints the connected account'
pass 'eas connect'

# --- the access token must never reach the request log in the clear ---
dry=$("$CLI" --dry-run eas connect --token expo-token-value 2>&1)
refute_contains "$dry" 'expo-token-value' 'eas connect dry-run must redact the token'
assert_contains "$dry" 'redacted' 'eas connect dry-run says the body is redacted'
pass 'eas connect redacts the token'

# --- eas status (app scope) ---
: >"$MOCK_LOG"
output=$("$CLI" eas status 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/expo' 'eas status hits the app expo endpoint'
assert_contains "$output" '@acme/customer-app' 'eas status prints the linked project'
pass 'eas status'

# --- eas sync resolves scope from the flag ---
: >"$MOCK_LOG"
"$CLI" eas sync --app customer-app >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/expo/sync' 'eas sync --app syncs the project'
: >"$MOCK_LOG"
"$CLI" eas sync --integration-id int-002 >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'integrations/int-002/expo/sync' 'eas sync --integration-id syncs the account'
pass 'eas sync scope resolution'

# --- ambiguous or missing scope is rejected ---
if "$CLI" eas sync --app customer-app --integration-id int-002 >/dev/null 2>&1; then
  fail 'eas sync must reject both scopes at once'
fi
if APP_MANAGER_APP='' "$CLI" eas sync >/dev/null 2>&1; then
  fail 'eas sync must require a scope'
fi
pass 'eas sync rejects ambiguous scope'

# --- eas disconnect, both scopes ---
: >"$MOCK_LOG"
output=$("$CLI" eas disconnect --app customer-app 2>&1)
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'DELETE' 'eas disconnect deletes'
assert_contains "$log_contents" 'apps/customer-app/expo' 'eas disconnect --app unlinks the project'
assert_contains "$output" 'unlinked' 'eas disconnect --app confirms'
: >"$MOCK_LOG"
output=$("$CLI" eas disconnect --integration-id int-002 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'integrations/int-002/expo' 'eas disconnect --integration-id removes the account'
assert_contains "$output" 'disconnected' 'eas disconnect --integration-id confirms'
pass 'eas disconnect'

# --- eas projects ---
: >"$MOCK_LOG"
output=$("$CLI" eas projects --integration-id int-002 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'integrations/int-002/expo/projects' 'eas projects lists account projects'
assert_contains "$output" '@acme/customer-app' 'eas projects prints project names'
pass 'eas projects'

# --- eas import ---
: >"$MOCK_LOG"
"$CLI" eas import --integration-id int-002 --project-id expo-proj-001 >/dev/null 2>&1
log_contents=$(cat "$MOCK_LOG")
assert_contains "$log_contents" 'POST' 'eas import posts'
assert_contains "$log_contents" 'expo/projects/expo-proj-001/import' 'eas import targets the project'
pass 'eas import'

# --- eas link ---
: >"$MOCK_LOG"
"$CLI" eas link --integration-id int-002 --mode existing \
  --project-id 11111111-2222-3333-4444-555555555555 >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/expo' 'eas link posts to the app expo endpoint'
dry=$("$CLI" --dry-run eas link --integration-id int-002 --mode existing \
  --project-id 11111111-2222-3333-4444-555555555555 2>&1)
assert_contains "$dry" '"mode": "existing"' 'eas link sends the mode'
assert_contains "$dry" '"expo_project_id"' 'eas link sends the expo project id'
assert_contains "$dry" '"integration_id": "int-002"' 'eas link sends the integration id'
pass 'eas link'

# --- link validation ---
if "$CLI" eas link --integration-id int-002 --mode existing >/dev/null 2>&1; then
  fail 'eas link must require --project-id when mode is existing'
fi
if "$CLI" eas link --integration-id int-002 --mode sideways >/dev/null 2>&1; then
  fail 'eas link must reject an unknown mode'
fi
pass 'eas link validation'

# --- eas auto-sync ---
dry=$("$CLI" --dry-run eas auto-sync --enabled true 2>&1)
assert_contains "$dry" 'PATCH' 'eas auto-sync patches'
assert_contains "$dry" 'expo/automatic-sync' 'eas auto-sync targets automatic-sync'
assert_contains "$dry" '"enabled": true' 'eas auto-sync sends a boolean'
if "$CLI" eas auto-sync --enabled maybe >/dev/null 2>&1; then
  fail 'eas auto-sync must reject a non-boolean'
fi
pass 'eas auto-sync'

# --- eas identifiers + sync-identifiers ---
: >"$MOCK_LOG"
output=$("$CLI" eas identifiers 2>&1)
assert_contains "$(cat "$MOCK_LOG")" 'apps/customer-app/expo/identifiers' 'eas identifiers lists identifiers'
assert_contains "$output" 'app_manager_only' 'eas identifiers prints the sync status'
: >"$MOCK_LOG"
"$CLI" eas sync-identifiers >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'expo/identifiers/sync' 'eas sync-identifiers posts to the sync endpoint'
pass 'eas identifiers'

# --- eas provision ---
: >"$MOCK_LOG"
"$CLI" eas provision --identifier-id ident-001 >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'expo/identifiers/ident-001/provision' 'eas provision targets the identifier'
dry=$("$CLI" --dry-run eas provision --identifier-id ident-001 --credential-ids cred-001,cred-002 2>&1)
assert_contains "$dry" '"cred-001"' 'eas provision sends the credential ids as an array'
assert_contains "$dry" '"cred-002"' 'eas provision sends every credential id'
pass 'eas provision'

# --- eas attach-credential ---
: >"$MOCK_LOG"
"$CLI" eas attach-credential --identifier-id ident-001 --credential-id cred-001 >/dev/null 2>&1
assert_contains "$(cat "$MOCK_LOG")" 'identifiers/ident-001/credentials/cred-001' 'eas attach-credential targets the pair'
pass 'eas attach-credential'

# --- help ---
help=$("$CLI" eas --help 2>&1)
assert_contains "$help" 'eas <action>' 'eas help shows usage'
assert_contains "$help" 'attach-credential' 'eas help lists every action'
pass 'eas --help'

printf '1..15\n'
