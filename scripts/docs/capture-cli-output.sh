#!/usr/bin/env bash

# Capture real appmctl output for the documentation.
#
# The docs show what the CLI actually prints, so the samples are recorded here
# rather than written by hand. Re-run this after changing CLI output and the diff
# shows exactly which pages go stale.
#
# Only read-only commands are captured, so the script is safe to re-run and the
# API key it needs carries no :write scopes. Write commands are documented from
# their usage signature instead.
#
# Usage:
#   export APP_MANAGER_API_KEY='...'      # read scopes only
#   export APP_MANAGER_TEAM='acme-mobile'
#   export APP_MANAGER_APP='customer-app'
#   export APP_MANAGER_INSTANCE_URL='http://localhost:8000'
#   scripts/docs/capture-cli-output.sh
#
# Seed the data first with scripts/docs/seed-capture-data.php.

set -eu

REPO_ROOT=$(cd "$(dirname "$0")/../.." && pwd)
CLI="${APPMCTL_BIN:-$REPO_ROOT/libs/cli/bin/appmctl}"
OUT_DIR="$REPO_ROOT/apps/docs/src/data/outputs"

[ -x "$CLI" ] || { printf 'appmctl not found at %s\n' "$CLI" >&2; exit 1; }
[ -n "${APP_MANAGER_API_KEY:-}" ] || { printf 'APP_MANAGER_API_KEY is required\n' >&2; exit 1; }
[ -n "${APP_MANAGER_TEAM:-}" ] || { printf 'APP_MANAGER_TEAM is required\n' >&2; exit 1; }

mkdir -p "$OUT_DIR"

captured=0
failed=0

# capture <slug> <args...>
# Records stdout and stderr together: an error response is itself worth showing,
# and hiding stderr would silently produce an empty sample.
capture() {
  local slug="$1"
  shift
  local target="$OUT_DIR/$slug.txt"
  local status=0

  if ! "$CLI" "$@" >"$target.tmp" 2>&1; then
    status=$?
  fi

  # A capture is only useful if the CLI produced something. An empty file means
  # the command failed before printing anything, which would ship as a blank
  # code block.
  if [ ! -s "$target.tmp" ]; then
    rm -f "$target.tmp"
    printf '  FAIL %-32s (no output, exit %s)\n' "$slug" "$status" >&2
    failed=$((failed + 1))
    return 0
  fi

  mv "$target.tmp" "$target"
  if [ "$status" -eq 0 ]; then
    printf '  ok   %-32s (%s lines)\n' "$slug" "$(wc -l <"$target" | tr -d '[:space:]')"
  else
    printf '  ok   %-32s (%s lines, exit %s -- error sample)\n' \
      "$slug" "$(wc -l <"$target" | tr -d '[:space:]')" "$status"
  fi
  captured=$((captured + 1))
}

printf 'capturing into %s\n' "${OUT_DIR#"$REPO_ROOT/"}"

# --- applications ----------------------------------------------------------
capture apps-list apps list
capture apps-get apps get "$APP_MANAGER_APP"

# --- identifiers -----------------------------------------------------------
capture identifiers-list identifiers list

# --- files -----------------------------------------------------------------
capture files-list files list

# --- assets ----------------------------------------------------------------
capture assets-list assets list

# --- credentials -----------------------------------------------------------
capture credentials-list credentials list
capture credentials-types credentials types

# --- builds ----------------------------------------------------------------
capture builds-list builds list

# "builds list --status" is not captured on purpose: the API index accepts no
# status filter, so the CLI sends it and the response is unfiltered. Capturing it
# would ship a sample that looks like the flag works.

# --- integrations, pipelines, webhooks -------------------------------------
capture integrations-list integrations list
capture pipelines-list pipelines list --integration-id "$(
  "$CLI" --json integrations list 2>/dev/null |
    "${JQ_BIN:-jq}" -r '.data[] | select(.provider == "github") | .id' | head -1
)"
capture webhooks-list webhooks list

printf '%s captured' "$captured"
if [ "$failed" -gt 0 ]; then
  printf ', %s produced no output' "$failed"
fi
printf '\n'

# Build-scoped and identifier-scoped captures need an ID from the data above, so
# they run in a second pass once the first has proven the instance answers.
build_id=$("$CLI" --json builds list 2>/dev/null | "${JQ_BIN:-jq}" -r '.data[0].id // empty' || true)

if [ -n "$build_id" ]; then
  capture builds-get builds get "$build_id"
  capture logs-get logs get --build-id "$build_id"
  capture artifacts-list artifacts list --build-id "$build_id"
else
  printf '  skip build-scoped captures: no build id available\n' >&2
fi

identifier_id=$("$CLI" --json identifiers list 2>/dev/null | "${JQ_BIN:-jq}" -r '.data[0].id // empty' || true)

if [ -n "$identifier_id" ]; then
  capture identifiers-show identifiers show "$identifier_id"
else
  printf '  skip identifiers show: no identifier id available\n' >&2
fi

file_name=$("$CLI" --json files list 2>/dev/null | "${JQ_BIN:-jq}" -r '.data[0].name // empty' || true)

if [ -n "$file_name" ]; then
  capture files-show files show --name "$file_name"
else
  printf '  skip files show: no file name available\n' >&2
fi

# The API key must never end up in a captured file. This is a guard against a
# future command echoing its configuration.
if grep -rl -- "$APP_MANAGER_API_KEY" "$OUT_DIR" 2>/dev/null | head -1 | grep -q .; then
  printf 'ABORT: the API key leaked into a captured file\n' >&2
  exit 1
fi

printf 'done\n'
