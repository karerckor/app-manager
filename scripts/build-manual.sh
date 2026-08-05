#!/usr/bin/env bash

# Create a manual build and drive it through a full lifecycle.
# Posts sample build logs at each stage. Auto-resolves platform identifiers.
# Applies colored tags via the web tags endpoint.
#
# Required env:
#   APP_MANAGER_API_KEY, APP_MANAGER_TEAM, APP_MANAGER_INSTANCE_URL
#
# Usage:
#   scripts/build-manual.sh [--status completed|failed|cancelled] [--app APP]
#       [--platform ios|android|web] [--profile PROFILE]
#       [--version VER] [--context JSON] [--delay SECONDS]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CLI="$SCRIPT_DIR/../libs/cli/bin/appmctl"

status="completed"
app="${APP_MANAGER_APP:-example-app}"
platform=""
profile=""
version=""
context=""
delay=10

while [ "$#" -gt 0 ]; do
  case "$1" in
    --status) status="$2"; shift 2 ;;
    --app) app="$2"; shift 2 ;;
    --platform) platform="$2"; shift 2 ;;
    --profile) profile="$2"; shift 2 ;;
    --version) version="$2"; shift 2 ;;
    --context) context="$2"; shift 2 ;;
    --delay) delay="$2"; shift 2 ;;
    -h|--help)
      cat <<EOF
Usage: $(basename "$0") [options]

Create a manual build, run it through a lifecycle, and post sample logs.

Options:
  --status STATUS     Final status: completed (default), failed, cancelled
  --app APP           App slug (default: example-app)
  --platform PLAT     Target platform: ios, android, or web (auto-selects identifier)
  --profile PROFILE   Build profile (default depends on platform)
  --version VER       App version for tags (default: 1.0.0)
  --context JSON      Extra inline JSON context
  --delay SECONDS     Seconds between running and final status (default: 10)

Examples:
  $(basename "$0") --status completed
  $(basename "$0") --status failed --platform ios
  $(basename "$0") --platform web --version 2.1.0 --delay 5
EOF
      exit 0
      ;;
    *) echo "unknown option: $1" >&2; exit 1 ;;
  esac
done

case "$status" in
  completed|failed|cancelled) ;;
  *) echo "invalid status: $status (must be completed, failed, or cancelled)" >&2; exit 1 ;;
esac

if [ -n "$platform" ]; then
  case "$platform" in
    ios|android|web) ;;
    *) echo "invalid platform: $platform (must be ios, android, or web)" >&2; exit 1 ;;
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
    echo "Available identifiers:" >&2
    printf '%s' "$body" | jq -r '(.data // [])[] | "  \(.platform): \(.identifier) (\(.id))"' >&2
    exit 1
  fi

  identifier_label=$(printf '%s' "$body" | jq -r --arg id "$identifier_id" '
    (.data // [])[] | select(.id == $id) | "\(.platform) / \(.identifier)"
  ')
}

resolve_base_url

if [ -n "$platform" ]; then
  echo "Resolving $platform identifier..."
  resolve_identifier "$platform"
  echo "  Resolved: $identifier_label"
fi

# --- Default profile per platform -----------------------------------------
if [ -z "$profile" ]; then
  case "$platform" in
    ios) profile="appstore" ;;
    *) profile="production" ;;
  esac
fi

# --- Build tags (colored) --------------------------------------------------
if [ -z "$version" ]; then
  version="1.0.0"
fi

# Platform colors: android=green, ios=blue, web=purple, version=amber
platform_color=""
case "$platform" in
  android) platform_color="#22c55e" ;;
  ios)     platform_color="#3b82f6" ;;
  web)     platform_color="#a855f7" ;;
esac

tag_args=()
if [ -n "$platform_color" ]; then
  tag_args+=(--tag "$platform:$platform_color")
else
  tag_args+=(--tag "manual:#64748b")
fi
tag_args+=(--tag "$version:#f59e0b")

# --- Log content templates --------------------------------------------------
post_logs() {
  local stage="$1" bid="$2" plat="$3" step="$4"
  local content
  content=$(log_content "$stage" "$plat" "$step")
  "$CLI" logs post --app "$app" --build-id "$bid" --content "$content" 2>/dev/null || true
}

log_content() {
  local stage="$1" plat="$2" step="$3"
  case "$plat" in
    ios)
      case "$stage" in
        created)   printf '[%s] Build queued\n[%s] Resolving signing credentials...\n' "$step" "$step" ;;
        running)   printf '[%s] Downloading certificate and provisioning profile...\n[%s] Compiling Swift sources...\n[%s] Code signing IPA...\n' "$step" "$step" "$step" ;;
        completed) printf '[%s] Archive created successfully\n[%s] Uploading to App Store Connect...\n[%s] Build completed.\n' "$step" "$step" "$step" ;;
        failed)    printf '[%s] Archive created successfully\n[%s] Code signing failed: provisioning profile expired\n[%s] Build FAILED.\n' "$step" "$step" "$step" ;;
        cancelled) printf '[%s] Build cancelled by user.\n' "$step" ;;
      esac ;;
    android)
      case "$stage" in
        created)   printf '[%s] Build queued\n[%s] Resolving keystore...\n' "$step" "$step" ;;
        running)   printf '[%s] ./gradlew bundleRelease\n[%s] Compiling Java/Kotlin sources...\n[%s] Signing AAB with production keystore...\n' "$step" "$step" "$step" ;;
        completed) printf '[%s] BUILD SUCCESSFUL in 2m 14s\n[%s] Artifact: app-release.aab (48.2 MB)\n[%s] Build completed.\n' "$step" "$step" "$step" ;;
        failed)    printf '[%s] ./gradlew bundleRelease\n[%s] Compilation failed: Unresolved reference\n[%s] Build FAILED.\n' "$step" "$step" "$step" ;;
        cancelled) printf '[%s] Build cancelled by user.\n' "$step" ;;
      esac ;;
    web|*)
      case "$stage" in
        created)   printf '[%s] Build queued\n[%s] Installing dependencies...\n' "$step" "$step" ;;
        running)   printf '[%s] npm ci\n[%s] tsc --noEmit\n[%s] vite build\n[%s] Uploading to CDN...\n' "$step" "$step" "$step" "$step" ;;
        completed) printf '[%s] Deploy preview ready\n[%s] https://preview-abc123.example.app\n[%s] Build completed.\n' "$step" "$step" "$step" ;;
        failed)    printf '[%s] npm ci\n[%s] tsc --noEmit\n[%s] Error: Type mismatch in src/App.tsx:42\n[%s] Build FAILED.\n' "$step" "$step" "$step" "$step" ;;
        cancelled) printf '[%s] Build cancelled by user.\n' "$step" ;;
      esac ;;
  esac
}

# --- Show build plan -------------------------------------------------------
echo ""
echo "Build plan:"
echo "  App:        $app"
[ -n "$identifier_label" ] && echo "  Identifier: $identifier_label"
echo "  Profile:    $profile"
echo "  Status:     pending → running → $status"
echo "  Tags:       $(printf '%s ' "${tag_args[@]}" | sed 's/--tag //g')"
echo "  Version:    $version"
echo "  Delay:      ${delay}s"
echo ""

# --- Create build ----------------------------------------------------------
echo "Creating build..."
create_args=(--app "$app" --profile "$profile" --json "${tag_args[@]}")
[ -n "$identifier_id" ] && create_args+=(--app-identifier-id "$identifier_id")
[ -n "$context" ] && create_args+=(--context "$context")

create_json=$("$CLI" builds create "${create_args[@]}")
build_id=$(printf '%s' "$create_json" | jq -r '.data.id')
echo "  Build $build_id created (pending)"
echo "  Tags applied: $(printf '%s' "$create_json" | jq -r '[.data.tags[][0]] | join(", ")')"

# --- Post initial logs -----------------------------------------------------
post_logs "created" "$build_id" "$platform" "1/4"
echo "  Logs posted (created)"

# --- Handle cancel early ---------------------------------------------------
if [ "$status" = "cancelled" ]; then
  echo "Cancelling build $build_id..."
  "$CLI" builds cancel "$build_id" --app "$app" >/dev/null
  post_logs "cancelled" "$build_id" "$platform" "2/4"
  echo "  Build $build_id cancelled"
  echo ""
  echo "Done. Final status: cancelled"
  exit 0
fi

# --- Start build -----------------------------------------------------------
echo "Starting build $build_id..."
"$CLI" builds patch "$build_id" --app "$app" --status running >/dev/null
post_logs "running" "$build_id" "$platform" "2/4"
echo "  Build $build_id running"

# --- Simulate work ---------------------------------------------------------
echo "  Working for ${delay}s..."
sleep "$delay"

# --- Finish build ----------------------------------------------------------
echo "Finishing build (status=$status)..."
"$CLI" builds patch "$build_id" --app "$app" --status "$status" >/dev/null
post_logs "$status" "$build_id" "$platform" "4/4"
echo "  Build $build_id $status"

echo ""
echo "Done. Final status: $status"
