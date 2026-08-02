#!/usr/bin/env bash

# Configuration: environment variables, validation, URL construction.

DRY_RUN=0
PRETTY_JSON=0
OUTPUT_FILE=""
FORCE_OUTPUT=0
INSTANCE_URL="${APP_MANAGER_INSTANCE_URL:-http://localhost:8000}"
TEAM="${APP_MANAGER_TEAM:-}"
API_KEY="${APP_MANAGER_API_KEY:-}"
APP="${APP_MANAGER_APP:-}"
CURL_BIN="${APP_MANAGER_CURL_BIN:-curl}"
JQ_BIN="${APP_MANAGER_JQ_BIN:-jq}"
POLLING_INTERVAL="${APP_MANAGER_POLLING_INTERVAL:-10}"
ALLOW_INSECURE_HTTP="${APP_MANAGER_ALLOW_INSECURE_HTTP:-0}"

validate_slug() {
  local label="$1"
  local value="$2"
  case "$value" in
    ''|.|..|*[!A-Za-z0-9._-]*) die "$label must contain only letters, numbers, '.', '_' or '-'" ;;
  esac
}

require_config() {
  [ -n "$API_KEY" ] || die "APP_MANAGER_API_KEY is required"
  [ -n "$TEAM" ] || die "APP_MANAGER_TEAM is required"
  case "$API_KEY" in
    *$'\n'*|*$'\r'*) die "APP_MANAGER_API_KEY must not contain line breaks" ;;
  esac
  validate_slug "APP_MANAGER_TEAM" "$TEAM"
}

normalize_api_root() {
  local value="$INSTANCE_URL"

  case "$value" in
    http://*|https://*) ;;
    *) die "APP_MANAGER_INSTANCE_URL must start with http:// or https://" ;;
  esac
  case "$value" in
    *\?*|*\#*) die "APP_MANAGER_INSTANCE_URL must not contain a query string or fragment" ;;
  esac

  while [ "${value%/}" != "$value" ]; do
    value=${value%/}
  done
  case "$value" in
    */api/v1) value=${value%/api/v1} ;;
  esac
  case "$value" in
    http://?*|https://?*) ;;
    *) die "APP_MANAGER_INSTANCE_URL must include a host" ;;
  esac
  case "$value" in
    http://localhost|http://localhost:*|http://127.0.0.1|http://127.0.0.1:*|http://\[::1\]|http://\[::1\]:*) ;;
    http://*)
      [ "$ALLOW_INSECURE_HTTP" = "1" ] || die "APP_MANAGER_INSTANCE_URL must use HTTPS for non-loopback hosts (set APP_MANAGER_ALLOW_INSECURE_HTTP=1 to override)"
      ;;
  esac

  printf '%s/api/v1' "$value"
}

build_url() {
  local path="$1"
  local root

  case "$path" in
    http://*|https://*) die "PATH must be relative to the team API root" ;;
  esac
  path=${path#/}
  [ -n "$path" ] || die "PATH must not be empty"
  case "/$path/" in
    */../*|*/./*) die "PATH must not contain '.' or '..' segments" ;;
  esac

  root=$(normalize_api_root) || return 1
  printf '%s/%s/%s' "$root" "$TEAM" "$path"
}

require_curl() {
  command_exists "$CURL_BIN" || die "curl is required (looked for '$CURL_BIN')"
}

require_jq() {
  command_exists "$JQ_BIN" || die "jq is required for this operation (looked for '$JQ_BIN')"
}

require_app() {
  [ -n "$APP" ] || die "--app or APP_MANAGER_APP is required"
  validate_slug "app" "$APP"
}

validate_polling_interval() {
  case "$POLLING_INTERVAL" in
    ''|*[!0-9]*) die "APP_MANAGER_POLLING_INTERVAL must be a positive integer" ;;
  esac
  if [ "$POLLING_INTERVAL" -lt 1 ]; then
    die "APP_MANAGER_POLLING_INTERVAL must be a positive integer"
  fi
}
