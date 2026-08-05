#!/usr/bin/env bash

# Create an EAS/Expo build via appmctl. The platform reconciles status from
# EAS automatically after creation. Applies colored tags.
#
# Required env:
#   APP_MANAGER_API_KEY, APP_MANAGER_TEAM, APP_MANAGER_INSTANCE_URL
#
# Usage:
#   scripts/build-eas.sh --expo-build-id ID [--app APP] [--platform ios|android]
#       [--profile PROFILE] [--version VER] [--wait]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CLI="$SCRIPT_DIR/../libs/cli/bin/appmctl"

expo_build_id=""
app="${APP_MANAGER_APP:-example-app}"
platform=""
profile=""
version=""
context=""
wait_for_terminal=0

while [ "$#" -gt 0 ]; do
  case "$1" in
    --expo-build-id) expo_build_id="$2"; shift 2 ;;
    --app) app="$2"; shift 2 ;;
    --platform) platform="$2"; shift 2 ;;
    --profile) profile="$2"; shift 2 ;;
    --version) version="$2"; shift 2 ;;
    --context) context="$2"; shift 2 ;;
    --wait) wait_for_terminal=1; shift ;;
    -h|--help)
      cat <<EOF
Usage: $(basename "$0") [options]

Create an EAS build. The platform reconciles status from Expo automatically.

Options:
  --expo-build-id ID  Expo/EAS build ID (required)
  --app APP           App slug (default: example-app)
  --platform PLAT     Target platform: ios or android (auto-selects identifier)
  --profile PROFILE   Build profile (default depends on platform)
  --version VER       App version for tags (default: 1.0.0)
  --context JSON      Extra inline JSON context
  --wait              Poll until terminal status (completed/failed/cancelled)
EOF
      exit 0
      ;;
    *) echo "unknown option: $1" >&2; exit 1 ;;
  esac
done

[ -n "$expo_build_id" ] || { echo "--expo-build-id is required" >&2; exit 1; }

if [ -n "$platform" ]; then
  case "$platform" in
    ios|android) ;;
    *) echo "invalid platform: $platform (must be ios or android for EAS)" >&2; exit 1 ;;
  esac
fi

# --- Resolve platform identifier ------------------------------------------
identifier_id=""
identifier_label=""
base_url=""

resolve_base_url() {
  # Pass --app: without it the dry-run dies when APP_MANAGER_APP is unset and the
  # app came from --app. `|| true` keeps pipefail from killing the script silently
  # when grep finds no url line, so the check below can explain what went wrong.
  local url
  url=$("$CLI" --dry-run builds create --app "$app" --profile production 2>&1 | grep '^url:' | head -1 | sed 's/^url: //') || true
  base_url="${url%/api/v1/*}"

  if [ -z "$base_url" ]; then
    echo "Could not determine the instance URL. Set APP_MANAGER_INSTANCE_URL" >&2
    echo "(e.g. http://localhost:8000) and make sure appmctl is configured." >&2
    exit 1
  fi
}

resolve_identifier() {
  local plat="$1"
  local team_slug="${APP_MANAGER_TEAM:?APP_MANAGER_TEAM is required}"
  local api_key="${APP_MANAGER_API_KEY:?APP_MANAGER_API_KEY is required}"
  local list_url="$base_url/api/v1/$team_slug/apps/$app/identifiers"

  # Keep the status code and the body apart: an auth or slug problem returns a
  # JSON error, and parsing that for identifiers would report the misleading
  # "no identifier found" instead of the real cause.
  local resp http_code body
  resp=$(curl --silent --show-error --max-time 10 \
    --header "Authorization: Bearer $api_key" \
    --header "Accept: application/json" \
    --write-out '\n%{http_code}' \
    "$list_url") || { echo "Could not reach $base_url — is the instance running?" >&2; exit 1; }

  http_code="${resp##*$'\n'}"
  body="${resp%$'\n'*}"

  if [ "$http_code" != "200" ]; then
    echo "Failed to list identifiers for '$app' (HTTP $http_code)." >&2
    case "$http_code" in
      401|403) echo "  Check APP_MANAGER_API_KEY." >&2 ;;
      404) echo "  Check APP_MANAGER_TEAM ('$team_slug') and the app slug ('$app')." >&2 ;;
    esac
    printf '%s' "$body" | jq -r 'if type == "object" and has("message") then "  API: \(.message)" else empty end' >&2 2>/dev/null || true
    exit 1
  fi

  identifier_id=$(printf '%s' "$body" | jq -r --arg p "$plat" '
    (.data // [])[] | select(.platform == $p) | .id // empty
  ' | head -1)

  if [ -z "$identifier_id" ]; then
    echo "No $plat identifier found for app '$app'" >&2
    printf '%s' "$body" | jq -r '(.data // [])[] | "  \(.platform): \(.identifier) (\(.id))"' >&2
    exit 1
  fi

  identifier_label=$(printf '%s' "$body" | jq -r --arg id "$identifier_id" '
    (.data // [])[] | select(.id == $id) | "\(.platform) / \(.identifier)"
  ')
}

team_slug="${APP_MANAGER_TEAM:?APP_MANAGER_TEAM is required}"

# --- Preflight: the app must be linked to an Expo project ------------------
# An EAS build is only accepted when the app has a live Expo link AND the given
# build ID still shows up in that project's recent builds on expo.dev. Check the
# link up front so the failure is actionable instead of a raw 422 from the API.
assert_expo_linked() {
  local team_slug="${APP_MANAGER_TEAM:?APP_MANAGER_TEAM is required}"
  local api_key="${APP_MANAGER_API_KEY:?APP_MANAGER_API_KEY is required}"
  local code

  code=$(curl --silent --show-error --max-time 10 \
    --header "Authorization: Bearer $api_key" \
    --header "Accept: application/json" \
    --output /dev/null --write-out '%{http_code}' \
    "$base_url/api/v1/$team_slug/apps/$app/expo") || return 0

  [ "$code" = "200" ] && return 0

  cat >&2 <<EOF
App '$app' is not linked to an Expo project (GET /apps/$app/expo returned $code).

EAS builds cannot be created without one: the API verifies the build ID against
the project's recent builds on expo.dev, so a placeholder ID never works.

To use this script:
  1. Connect an Expo account   → Integrations → Expo
  2. Link a project to the app → app page → "Expo EAS" tab
  3. Use a real build ID       → 'eas build:list' or expo.dev

For a presentation without an Expo account, use scripts/build-manual.sh instead —
it drives the same build lifecycle, logs, and tags locally.
EOF
  exit 1
}

resolve_base_url
assert_expo_linked

if [ -n "$platform" ]; then
  echo "Resolving $platform identifier..."
  resolve_identifier "$platform"
  echo "  Resolved: $identifier_label"
fi

# --- Defaults --------------------------------------------------------------
if [ -z "$profile" ]; then
  case "$platform" in
    ios) profile="appstore" ;;
    *) profile="production" ;;
  esac
fi

if [ -z "$version" ]; then
  version="1.0.0"
fi

# --- Tags (colored) --------------------------------------------------------
platform_color=""
case "$platform" in
  android) platform_color="#22c55e" ;;
  ios)     platform_color="#3b82f6" ;;
esac

tag_args=(--tag "expo:#f97316")
[ -n "$platform_color" ] && tag_args+=(--tag "$platform:$platform_color")
tag_args+=(--tag "$version:#f59e0b")

# --- Show build plan -------------------------------------------------------
echo ""
echo "EAS Build plan:"
echo "  App:           $app"
[ -n "$identifier_label" ] && echo "  Identifier:    $identifier_label"
echo "  Profile:       $profile"
echo "  Expo build ID: $expo_build_id"
echo "  Tags:          $(printf '%s ' "${tag_args[@]}" | sed 's/--tag //g')"
echo "  Version:       $version"
echo ""

# --- Create EAS build ------------------------------------------------------
echo "Creating EAS build..."
create_args=(--app "$app" --profile "$profile" --reporting-mode eas --expo-build-id "$expo_build_id" --json "${tag_args[@]}")
[ -n "$identifier_id" ] && create_args+=(--app-identifier-id "$identifier_id")
[ -n "$context" ] && create_args+=(--context "$context")

create_err=$(mktemp "${TMPDIR:-/tmp}/build-eas-err.XXXXXX")
if ! create_json=$("$CLI" builds create "${create_args[@]}" 2>"$create_err"); then
  cat "$create_err" >&2
  rm -f -- "$create_err"
  cat >&2 <<EOF

The build ID must belong to the Expo project linked to '$app' and still appear in
its recent builds on expo.dev. List valid IDs with:

  curl -s -H "Authorization: Bearer \$APP_MANAGER_API_KEY" -H "Accept: application/json" \\
    "$base_url/api/v1/$team_slug/apps/$app/expo" \\
    | jq -r '.data.remote.builds[] | "\\(.id)  \\(.platform)  \\(.status)  \\(.appVersion)"'
EOF
  exit 1
fi
rm -f -- "$create_err"
build_id=$(printf '%s' "$create_json" | jq -r '.data.id')
build_status=$(printf '%s' "$create_json" | jq -r '.data.status')
echo "  Build $build_id created (status: $build_status)"
echo "  Tags applied: $(printf '%s' "$create_json" | jq -r '[.data.tags[][0]] | join(", ")')"

if [ "$wait_for_terminal" -eq 0 ]; then
  echo ""
  echo "Done. The platform will reconcile status from EAS in the background."
  echo "Use 'appmctl builds wait $build_id --app $app' to poll."
  exit 0
fi

# --- Wait for terminal status ---------------------------------------------
echo "  Waiting for EAS reconciliation..."
"$CLI" builds wait "$build_id" --app "$app"
