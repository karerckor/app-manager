#!/usr/bin/env bash

# "builds" subcommands: list, get, create, cancel, patch.

builds_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] builds <action> [options]

Actions:
  list                List builds for an app
  get                 Show build details
  create              Create a manual build
  cancel              Cancel a build
  patch               Update build status or metadata

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --build-id ID       Build ID (required for get, cancel, patch)
  --status STATUS     Filter by status (list) or set status (patch)
  --profile TYPE      Build profile type (create)
  --request-uuid UUID Idempotency key (create)
  --context JSON      Build context metadata (create)
  --page N            Page number (list)

Run "appmctl builds <action> --help" for action-specific help.
EOF
}

builds_list_usage() {
  cat <<'EOF'
Usage:
  appmctl builds list [--app APP] [--status STATUS] [--page N]

List builds for an application.

Examples:
  appmctl builds list --app customer-app
  appmctl builds list --status running
  appmctl builds list --json
EOF
}

builds_get_usage() {
  cat <<'EOF'
Usage:
  appmctl builds get [--app APP] <build-id>

Show details for a single build.

Examples:
  appmctl builds get bld-abc123
  appmctl builds get --app customer-app bld-abc123
EOF
}

builds_create_usage() {
  cat <<'EOF'
Usage:
  appmctl builds create [--app APP] [options]

Create a manual build.

Options:
  --profile TYPE        Build profile (e.g. production, staging)
  --request-uuid UUID   Idempotency key for CI retries
  --context JSON        Inline JSON context/metadata

Examples:
  appmctl builds create --app customer-app --profile production
  appmctl builds create --profile staging --request-uuid "$(uuidgen)"
  appmctl builds create --profile production --context '{"runner":"github"}'
EOF
}

builds_cancel_usage() {
  cat <<'EOF'
Usage:
  appmctl builds cancel [--app APP] <build-id>

Cancel a build.

Examples:
  appmctl builds cancel bld-abc123
EOF
}

builds_patch_usage() {
  cat <<'EOF'
Usage:
  appmctl builds patch [--app APP] <build-id> --status STATUS

Update a build's status (for manual builds with force-status).

Examples:
  appmctl builds patch bld-abc123 --status completed
  appmctl builds patch bld-abc123 --status failed
EOF
}

command_builds() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _builds_list "$@" ;;
    get) _builds_get "$@" ;;
    create) _builds_create "$@" ;;
    cancel) _builds_cancel "$@" ;;
    patch) _builds_patch "$@" ;;
    -h|--help|"") builds_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown builds action: $action (use list, get, create, cancel, patch)" ;;
  esac
}

_builds_list() {
  local app="${APP:-}"
  local status_filter=""
  local page=""
  local query=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --status) require_value "$1" "$2"; status_filter="$2"; shift 2 ;;
      --status=*) status_filter=${1#*=}; shift ;;
      --page) require_value "$1" "$2"; page="$2"; shift 2 ;;
      --page=*) page=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) builds_list_usage; return 0 ;;
      *) die "unknown builds list option: $1" ;;
    esac
  done

  APP="$app"
  require_app

  if [ -n "$status_filter" ] || [ -n "$page" ]; then
    query="?"
    [ -n "$status_filter" ] && query="${query}status=${status_filter}"
    [ -n "$page" ] && query="${query}${query:+&}page=${page}"
  fi

  local path="apps/$APP/builds${query}"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "$path" || return 1
  local fmt='{"type":"table","fields":["id","profile_type","status","created_at"],"headers":["id","profile","status","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_builds_get() {
  local app="${APP:-}"
  local build_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) builds_get_usage; return 0 ;;
      -*) die "unknown builds get option: $1" ;;
      *)
        [ -z "$build_id" ] || die "unexpected argument: $1"
        build_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "build ID is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "$path" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","app":"app.slug","profile":"profile_type","status":"status","request_uuid":"request_uuid","created_at":"created_at","started_at":"started_at","finished_at":"finished_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_builds_create() {
  local app="${APP:-}"
  local profile=""
  local request_uuid=""
  local context=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --profile) require_value "$1" "$2"; profile="$2"; shift 2 ;;
      --profile=*) profile=${1#*=}; shift ;;
      --request-uuid) require_value "$1" "$2"; request_uuid="$2"; shift 2 ;;
      --request-uuid=*) request_uuid=${1#*=}; shift ;;
      --context) require_value "$1" "$2"; context="$2"; shift 2 ;;
      --context=*) context=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) builds_create_usage; return 0 ;;
      *) die "unknown builds create option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$profile" ] || die "--profile is required"

  if [ -z "$request_uuid" ]; then
    if command_exists uuidgen; then
      request_uuid=$(uuidgen)
    else
      request_uuid=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || printf '%s-%s-%s-%s-%s' \
        "$(head -c 8 /dev/urandom | xxd -p)" \
        "$(head -c 4 /dev/urandom | xxd -p)" \
        "$(head -c 4 /dev/urandom | xxd -p)" \
        "$(head -c 4 /dev/urandom | xxd -p)" \
        "$(head -c 12 /dev/urandom | xxd -p)")
    fi
  fi

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  if [ -n "$context" ]; then
    "$JQ_BIN" -n --arg pid "$request_uuid" --arg pf "$profile" --argjson ctx "$context" \
      '{request_uuid: $pid, profile_type: $pf, context: $ctx}' >"$body_file"
  else
    "$JQ_BIN" -n --arg pid "$request_uuid" --arg pf "$profile" \
      '{request_uuid: $pid, profile_type: $pf}' >"$body_file"
  fi

  local path="apps/$APP/builds"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    rm -f -- "$body_file"
    return 0
  fi

  api_post "$path" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  local fmt='{"type":"resource","fields":{"id":"id","status":"status","profile":"profile_type","request_uuid":"request_uuid","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_builds_cancel() {
  local app="${APP:-}"
  local build_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) builds_cancel_usage; return 0 ;;
      -*) die "unknown builds cancel option: $1" ;;
      *)
        [ -z "$build_id" ] || die "unexpected argument: $1"
        build_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "build ID is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id/cancel"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_post "$path" "" || return 1
  printf 'Build %s cancelled\n' "$build_id"
}

_builds_patch() {
  local app="${APP:-}"
  local build_id=""
  local new_status=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --status) require_value "$1" "$2"; new_status="$2"; shift 2 ;;
      --status=*) new_status=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) builds_patch_usage; return 0 ;;
      -*) die "unknown builds patch option: $1" ;;
      *)
        [ -z "$build_id" ] || die "unexpected argument: $1"
        build_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "build ID is required"
  [ -n "$new_status" ] || die "--status is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id"
  local body_file
  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  printf '{"status":"%s"}' "$new_status" >"$body_file"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: PATCH\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    rm -f -- "$body_file"
    return 0
  fi

  api_patch "$path" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  printf 'Build %s updated to %s\n' "$build_id" "$new_status"
}
