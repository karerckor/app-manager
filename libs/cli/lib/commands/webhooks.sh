#!/usr/bin/env bash

# "webhooks" subcommands: list, get, create, update, delete, test, rotate, deliveries, redeliver.

webhooks_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] webhooks <action> [options]

Actions:
  list                List webhook endpoints
  get                 Show webhook details
  create              Create a webhook endpoint
  update              Update a webhook endpoint
  delete              Delete a webhook endpoint
  test                Send a test delivery
  rotate              Rotate the HMAC signing secret
  deliveries          List deliveries for a webhook
  redeliver           Redeliver a specific delivery

Run "appmctl webhooks <action> --help" for action-specific help.
EOF
}

command_webhooks() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _webhooks_list "$@" ;;
    get) _webhooks_get "$@" ;;
    create) _webhooks_create "$@" ;;
    update) _webhooks_update "$@" ;;
    delete) _webhooks_delete "$@" ;;
    test) _webhooks_test "$@" ;;
    rotate) _webhooks_rotate "$@" ;;
    deliveries) _webhooks_deliveries "$@" ;;
    redeliver) _webhooks_redeliver "$@" ;;
    -h|--help|"") webhooks_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown webhooks action: $action (use list, get, create, update, delete, test, rotate, deliveries, redeliver)" ;;
  esac
}

_webhooks_list() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks list\n'
        return 0
        ;;
      *) die "unknown webhooks list option: $1" ;;
    esac
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "webhooks" || return 1
  local fmt='{"type":"table","fields":["id","url","subscribed_events // [] | join(\", \")","enabled"],"headers":["id","url","events","enabled"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_webhooks_get() {
  local webhook_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks get <webhook-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks get option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "webhooks/$webhook_id" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","url":"url","events":"subscribed_events // [] | join(\", \")","enabled":"enabled","secret_hint":"secret_hint","consecutive_failures":"consecutive_failures","last_delivered_at":"last_delivered_at","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_webhooks_create() {
  local url_val=""
  local events=""
  local app_filter=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --url) require_value "$1" "$2"; url_val="$2"; shift 2 ;;
      --url=*) url_val=${1#*=}; shift ;;
      --events) require_value "$1" "$2"; events="$2"; shift 2 ;;
      --events=*) events=${1#*=}; shift ;;
      --app) require_value "$1" "$2"; app_filter="$2"; shift 2 ;;
      --app=*) app_filter=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks create --url URL [--events JSON] [--app SLUG]\n'
        return 0
        ;;
      *) die "unknown webhooks create option: $1" ;;
    esac
  done

  [ -n "$url_val" ] || die "--url is required"

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  local jq_args=(-n --arg u "$url_val")
  local jq_expr='{url: $u'
  if [ -n "$events" ]; then
    jq_args+=(--argjson e "$events")
    jq_expr="$jq_expr, events: \$e"
  fi
  if [ -n "$app_filter" ]; then
    jq_args+=(--arg a "$app_filter")
    jq_expr="$jq_expr, app_filter: \$a"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    rm -f -- "$body_file"
    return 0
  fi

  api_post "webhooks" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  local fmt='{"type":"resource","fields":{"id":"id","url":"url","events":"subscribed_events // [] | join(\", \")","enabled":"enabled","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_webhooks_update() {
  local webhook_id=""
  local url_val=""
  local events=""
  local body_file
  local has_fields=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --url) require_value "$1" "$2"; url_val="$2"; has_fields=1; shift 2 ;;
      --url=*) url_val=${1#*=}; has_fields=1; shift ;;
      --events) require_value "$1" "$2"; events="$2"; has_fields=1; shift 2 ;;
      --events=*) events=${1#*=}; has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks update <webhook-id> [--url URL] [--events JSON]\n'
        return 0
        ;;
      -*) die "unknown webhooks update option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"
  [ "$has_fields" -eq 1 ] || die "at least one field to update is required"

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  local jq_args=(-n)
  local jq_expr='{'
  local first=1
  if [ -n "$url_val" ]; then
    jq_args+=(--arg u "$url_val")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr url: \$u"
    first=0
  fi
  if [ -n "$events" ]; then
    jq_args+=(--argjson e "$events")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr events: \$e"
    first=0
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  local path="webhooks/$webhook_id"

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
  printf 'Webhook %s updated\n' "$webhook_id"
}

_webhooks_delete() {
  local webhook_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks delete <webhook-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks delete option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: DELETE\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_delete "webhooks/$webhook_id" || return 1
  printf 'Webhook %s deleted\n' "$webhook_id"
}

_webhooks_test() {
  local webhook_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks test <webhook-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks test option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id/test") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_post "webhooks/$webhook_id/test" "" || return 1
  printf 'Test delivery sent for webhook %s\n' "$webhook_id"
}

_webhooks_rotate() {
  local webhook_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks rotate <webhook-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks rotate option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id/rotate-secret") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_post "webhooks/$webhook_id/rotate-secret" "" || return 1
  printf 'Secret rotated for webhook %s\n' "$webhook_id"
}

_webhooks_deliveries() {
  local webhook_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks deliveries <webhook-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks deliveries option: $1" ;;
      *)
        [ -z "$webhook_id" ] || die "unexpected argument: $1"
        webhook_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "webhook ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id/deliveries") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "webhooks/$webhook_id/deliveries" || return 1
  local fmt='{"type":"table","fields":["id","event_type","status","response_status","attempt_count","delivered_at"],"headers":["id","event","status","http","attempts","delivered_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_webhooks_redeliver() {
  local webhook_id=""
  local delivery_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --webhook-id) require_value "$1" "$2"; webhook_id="$2"; shift 2 ;;
      --webhook-id=*) webhook_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks redeliver --webhook-id ID <delivery-id>\n'
        return 0
        ;;
      -*) die "unknown webhooks redeliver option: $1" ;;
      *)
        [ -z "$delivery_id" ] || die "unexpected argument: $1"
        delivery_id="$1"; shift ;;
    esac
  done

  [ -n "$webhook_id" ] || die "--webhook-id is required"
  [ -n "$delivery_id" ] || die "delivery ID is required"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks/$webhook_id/deliveries/$delivery_id/redeliver") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_post "webhooks/$webhook_id/deliveries/$delivery_id/redeliver" "" || return 1
  printf 'Delivery %s redelivered\n' "$delivery_id"
}
