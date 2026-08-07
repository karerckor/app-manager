#!/usr/bin/env bash

# "apps" subcommands: list, get, create, update, delete, including blueprint attachment.

apps_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] apps <action> [options]

Actions:
  list                List team applications
  get                 Show application details
  create              Create a new application
  update              Update an application
  delete              Delete an application

Run "appmctl apps <action> --help" for action-specific help.
EOF
}

command_apps() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _apps_list "$@" ;;
    get) _apps_get "$@" ;;
    create) _apps_create "$@" ;;
    update) _apps_update "$@" ;;
    delete) _apps_delete "$@" ;;
    -h|--help|"") apps_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown apps action: $action (use list, get, create, update, delete)" ;;
  esac
}

_apps_list() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl apps list\n'
        return 0
        ;;
      *) die "unknown apps list option: $1" ;;
    esac
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "apps") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "apps" || return 1
  local fmt='{"type":"table","fields":["slug","name","created_at"],"headers":["slug","name","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_apps_get() {
  local slug=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl apps get <slug>\n'
        return 0
        ;;
      -*) die "unknown apps get option: $1" ;;
      *)
        [ -z "$slug" ] || die "unexpected argument: $1"
        slug="$1"
        shift
        ;;
    esac
  done

  [ -n "$slug" ] || die "app slug is required"
  validate_slug "app slug" "$slug"

  local path="apps/$slug"

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
  local fmt='{"type":"resource","fields":{"slug":"slug","name":"name","description":"description","blueprint_id":"blueprint_id","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_apps_create() {
  local name=""
  local slug=""
  local description=""
  local metadata=""
  local blueprint_id=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --slug) require_value "$1" "$2"; slug="$2"; shift 2 ;;
      --slug=*) slug=${1#*=}; shift ;;
      --description) require_value "$1" "$2"; description="$2"; shift 2 ;;
      --description=*) description=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --blueprint-id) require_value "$1" "$2"; blueprint_id="$2"; shift 2 ;;
      --blueprint-id=*) blueprint_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl apps create --name NAME [--slug SLUG] [--description DESC] [--metadata JSON] [--blueprint-id UUID]\n'
        return 0
        ;;
      *) die "unknown apps create option: $1" ;;
    esac
  done

  [ -n "$name" ] || die "--name is required"
  if [ -n "$blueprint_id" ]; then validate_slug "blueprint ID" "$blueprint_id"; fi

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  local jq_args=(-n --arg n "$name")
  local jq_expr='{name: $n'
  if [ -n "$slug" ]; then
    jq_args+=(--arg s "$slug")
    jq_expr="$jq_expr, slug: \$s"
  fi
  if [ -n "$description" ]; then
    jq_args+=(--arg d "$description")
    jq_expr="$jq_expr, description: \$d"
  fi
  if [ -n "$metadata" ]; then
    jq_args+=(--argjson m "$metadata")
    jq_expr="$jq_expr, metadata: \$m"
  fi
  if [ -n "$blueprint_id" ]; then
    jq_args+=(--arg blueprint "$blueprint_id")
    jq_expr="$jq_expr, blueprint_id: \$blueprint"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "apps") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    rm -f -- "$body_file"
    return 0
  fi

  api_post "apps" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  local fmt='{"type":"resource","fields":{"slug":"slug","name":"name","description":"description","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_apps_update() {
  local slug=""
  local name=""
  local description=""
  local metadata=""
  local blueprint_id=""
  local blueprint_id_set=0
  local body_file
  local has_fields=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; has_fields=1; shift 2 ;;
      --name=*) name=${1#*=}; has_fields=1; shift ;;
      --description) require_value "$1" "$2"; description="$2"; has_fields=1; shift 2 ;;
      --description=*) description=${1#*=}; has_fields=1; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; has_fields=1; shift 2 ;;
      --metadata=*) metadata=${1#*=}; has_fields=1; shift ;;
      --blueprint-id) require_value "$1" "$2"; blueprint_id="$2"; blueprint_id_set=1; has_fields=1; shift 2 ;;
      --blueprint-id=*) blueprint_id=${1#*=}; blueprint_id_set=1; has_fields=1; shift ;;
      --no-blueprint) blueprint_id=""; blueprint_id_set=1; has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl apps update <slug> [--name NAME] [--description DESC] [--metadata JSON] [--blueprint-id UUID|--no-blueprint]\n'
        return 0
        ;;
      -*) die "unknown apps update option: $1" ;;
      *)
        [ -z "$slug" ] || die "unexpected argument: $1"
        slug="$1"
        shift
        ;;
    esac
  done

  [ -n "$slug" ] || die "app slug is required"
  [ "$has_fields" -eq 1 ] || die "at least one field to update is required"
  validate_slug "app slug" "$slug"
  if [ "$blueprint_id_set" -eq 1 ] && [ -n "$blueprint_id" ]; then validate_slug "blueprint ID" "$blueprint_id"; fi

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  local jq_args=(-n)
  local jq_expr='{'
  local first=1
  if [ -n "$name" ]; then
    jq_args+=(--arg n "$name")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr name: \$n"
    first=0
  fi
  if [ -n "$description" ]; then
    jq_args+=(--arg d "$description")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr description: \$d"
    first=0
  fi
  if [ -n "$metadata" ]; then
    jq_args+=(--argjson m "$metadata")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr metadata: \$m"
    first=0
  fi
  if [ "$blueprint_id_set" -eq 1 ]; then
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    if [ -n "$blueprint_id" ]; then
      jq_args+=(--arg blueprint "$blueprint_id")
      jq_expr="$jq_expr blueprint_id: \$blueprint"
    else
      jq_expr="$jq_expr blueprint_id: null"
    fi
    first=0
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  local path="apps/$slug"

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
  printf 'App %s updated\n' "$slug"
}

_apps_delete() {
  local slug=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl apps delete <slug>\n'
        return 0
        ;;
      -*) die "unknown apps delete option: $1" ;;
      *)
        [ -z "$slug" ] || die "unexpected argument: $1"
        slug="$1"
        shift
        ;;
    esac
  done

  [ -n "$slug" ] || die "app slug is required"
  validate_slug "app slug" "$slug"

  local path="apps/$slug"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: DELETE\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_delete "$path" || return 1
  printf 'App %s deleted\n' "$slug"
}
