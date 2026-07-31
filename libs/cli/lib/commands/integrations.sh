#!/usr/bin/env bash

# "integrations" subcommands: list, get, repos, workflows, refs.

integrations_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] integrations <action> [options]

Actions:
  list                List team integrations
  get                 Show integration details
  repos               List repositories for an integration
  workflows           List workflows for an integration
  refs                List refs for an integration

Run "appmctl integrations <action> --help" for action-specific help.
EOF
}

command_integrations() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _integrations_list "$@" ;;
    get) _integrations_get "$@" ;;
    repos) _integrations_repos "$@" ;;
    workflows) _integrations_workflows "$@" ;;
    refs) _integrations_refs "$@" ;;
    -h|--help|"") integrations_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown integrations action: $action (use list, get, repos, workflows, refs)" ;;
  esac
}

_require_integration_id() {
  local var_name="$1"
  eval "[ -n \"\$$var_name\" ] || die \"--integration-id is required\""
}

_integrations_list() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl integrations list\n'
        return 0
        ;;
      *) die "unknown integrations list option: $1" ;;
    esac
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "integrations") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "integrations" || return 1
  local fmt='{"type":"table","fields":["id","provider","name","created_at"],"headers":["id","provider","name","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_integrations_get() {
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl integrations get <integration-id>\n'
        return 0
        ;;
      -*) die "unknown integrations get option: $1" ;;
      *)
        [ -z "$integration_id" ] || die "unexpected argument: $1"
        integration_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "integration ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "integrations/$integration_id") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "integrations/$integration_id" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","provider":"provider","name":"name","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_integrations_repos() {
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl integrations repos --integration-id ID\n'
        return 0
        ;;
      -*) die "unknown integrations repos option: $1" ;;
      *)
        [ -z "$integration_id" ] || die "unexpected argument: $1"
        integration_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "integrations/$integration_id/repositories") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "integrations/$integration_id/repositories" || return 1
  emit_response
}

_integrations_workflows() {
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl integrations workflows --integration-id ID\n'
        return 0
        ;;
      -*) die "unknown integrations workflows option: $1" ;;
      *)
        [ -z "$integration_id" ] || die "unexpected argument: $1"
        integration_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "integrations/$integration_id/workflows") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "integrations/$integration_id/workflows" || return 1
  emit_response
}

_integrations_refs() {
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl integrations refs --integration-id ID\n'
        return 0
        ;;
      -*) die "unknown integrations refs option: $1" ;;
      *)
        [ -z "$integration_id" ] || die "unexpected argument: $1"
        integration_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "integrations/$integration_id/refs") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "integrations/$integration_id/refs" || return 1
  emit_response
}
