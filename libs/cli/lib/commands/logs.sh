#!/usr/bin/env bash

# "logs" subcommands: get, post.

logs_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] logs <action> [options]

Actions:
  get             Fetch build logs
  post            Append log content to a build

Options:
  --app APP       App slug (default: APP_MANAGER_APP)
  --build-id ID   Build ID (required)
  --content TEXT  Log text to append (post)

Run "appmctl logs <action> --help" for action-specific help.
EOF
}

command_logs() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    get) _logs_get "$@" ;;
    post) _logs_post "$@" ;;
    -h|--help|"") logs_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown logs action: $action (use get, post)" ;;
  esac
}

_logs_get() {
  local app="${APP:-}"
  local build_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl logs get [--app APP] --build-id ID\n'
        return 0
        ;;
      *) die "unknown logs get option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id/logs"

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
  emit_response
}

_logs_post() {
  local app="${APP:-}"
  local build_id=""
  local content=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --content) require_value "$1" "$2"; content="$2"; shift 2 ;;
      --content=*) content=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl logs post [--app APP] --build-id ID --content TEXT\n'
        return 0
        ;;
      *) die "unknown logs post option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  [ -n "$content" ] || die "--content is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id/logs"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'content: %s\n' "$content"
    return 0
  fi

  require_curl
  require_jq
  ensure_tmpdir
  post_log_text "$(build_url "$path")" "$content"
  printf 'Log content appended to build %s\n' "$build_id"
}
