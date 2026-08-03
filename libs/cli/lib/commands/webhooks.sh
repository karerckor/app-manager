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
  local name=""
  local url_val=""
  local events=""
  local app_slugs=()
  local app_ids=()
  local build_statuses=()
  local filter_tags=()
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --url) require_value "$1" "$2"; url_val="$2"; shift 2 ;;
      --url=*) url_val=${1#*=}; shift ;;
      --events) require_value "$1" "$2"; events="$2"; shift 2 ;;
      --events=*) events=${1#*=}; shift ;;
      --app) require_value "$1" "$2"; app_slugs+=("$2"); shift 2 ;;
      --app=*) app_slugs+=("${1#*=}"); shift ;;
      --app-id) require_value "$1" "$2"; app_ids+=("$2"); shift 2 ;;
      --app-id=*) app_ids+=("${1#*=}"); shift ;;
      --build-status) require_value "$1" "$2"; build_statuses+=("$2"); shift 2 ;;
      --build-status=*) build_statuses+=("${1#*=}"); shift ;;
      --tag) require_value "$1" "$2"; filter_tags+=("$2"); shift 2 ;;
      --tag=*) filter_tags+=("${1#*=}"); shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        _webhooks_create_help
        return 0
        ;;
      *) die "unknown webhooks create option: $1" ;;
    esac
  done

  [ -n "$name" ] || die "--name is required"
  [ -n "$url_val" ] || die "--url is required"
  [ -n "$events" ] || die "--events is required (a JSON array of event names)"

  require_jq
  ensure_tmpdir

  local filters_json
  filters_json=$(_webhooks_filters_json \
    "$(_webhooks_join_array "${app_slugs[@]+"${app_slugs[@]}"}")" \
    "$(_webhooks_join_array "${app_ids[@]+"${app_ids[@]}"}")" \
    "$(_webhooks_join_array "${build_statuses[@]+"${build_statuses[@]}"}")" \
    "$(_webhooks_join_array "${filter_tags[@]+"${filter_tags[@]}"}")") || return 1

  body_file=$(new_temp_file webhooks-create) || return 1
  local jq_args=(-n --arg name "$name" --arg url "$url_val" --argjson events "$events")
  local jq_expr='{name: $name, url: $url, events: $events'
  if [ -n "$filters_json" ]; then
    jq_args+=(--argjson filters "$filters_json")
    jq_expr="$jq_expr, filters: \$filters"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" \
    || die "--events must be a JSON array"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "webhooks") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    return 0
  fi

  api_post "webhooks" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","url":"url","events":"subscribed_events // [] | join(\", \")","enabled":"enabled","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_webhooks_create_help() {
  cat <<'EOF'
Usage:
  appmctl webhooks create --name NAME --url URL --events JSON [filters]

Options:
  --name NAME           Endpoint name (required)
  --url URL             Destination URL (required)
  --events JSON         JSON array of event names, at least one (required)

Filters (all repeatable, all optional):
  --app SLUG            Only events for this app; resolved to an app id
  --app-id UUID         Same, when you already have the id
  --build-status STATUS Only builds reaching this status
  --tag TAG             Only builds carrying this tag

Examples:
  appmctl webhooks create --name 'Release notifier' \
    --url https://hooks.example.com/app-manager \
    --events '["build.created","build.completed"]'

  appmctl webhooks create --name 'Android releases' \
    --url https://hooks.example.com/android \
    --events '["build.completed"]' \
    --app customer-app --build-status completed --tag android
EOF
}

# The API takes filters as {mobile_app_ids, tags, build_statuses, profile_types}.
# App slugs are resolved to ids here because the filter keys on ids, and asking a
# reader to look up a UUID first would be a worse interface than one extra request.
_webhooks_filters_json() {
  local slug_list="$1"
  local id_list="$2"
  local status_list="$3"
  local tag_list="$4"

  local resolved_ids="$id_list"

  if [ -n "$slug_list" ]; then
    if [ "$DRY_RUN" -eq 1 ]; then
      # Resolving means calling the API, which --dry-run promises not to do.
      say_error "note: --app is resolved to an app id when the request is sent; dry-run shows the slug"
      local slug
      while IFS= read -r slug; do
        [ -n "$slug" ] || continue
        resolved_ids="${resolved_ids:+$resolved_ids$'\n'}slug:$slug"
      done <<<"$slug_list"
    else
      local slug id
      while IFS= read -r slug; do
        [ -n "$slug" ] || continue
        id=$(_webhooks_resolve_app_id "$slug") || return 1
        resolved_ids="${resolved_ids:+$resolved_ids$'\n'}$id"
      done <<<"$slug_list"
    fi
  fi

  if [ -z "$resolved_ids" ] && [ -z "$status_list" ] && [ -z "$tag_list" ]; then
    printf ''
    return 0
  fi

  require_jq
  "$JQ_BIN" -n \
    --arg ids "$resolved_ids" \
    --arg statuses "$status_list" \
    --arg tags "$tag_list" \
    '{}
     | if ($ids | length) > 0 then .mobile_app_ids = ($ids | split("\n") | map(select(length > 0))) else . end
     | if ($statuses | length) > 0 then .build_statuses = ($statuses | split("\n") | map(select(length > 0))) else . end
     | if ($tags | length) > 0 then .tags = ($tags | split("\n") | map(select(length > 0))) else . end'
}

_webhooks_resolve_app_id() {
  local slug="$1"
  validate_slug "app slug" "$slug"

  api_get "apps/$slug" >/dev/null || die "could not look up app \"$slug\""

  local id
  id=$("$JQ_BIN" -r '.data.id // empty' "$LAST_RESPONSE_FILE")
  [ -n "$id" ] || die "app \"$slug\" has no id in the API response"
  printf '%s' "$id"
}

# Newline-joined lists keep values with spaces intact, which a comma-joined string
# would not.
_webhooks_join_array() {
  local value
  for value in "$@"; do
    printf '%s\n' "$value"
  done
}

_webhooks_update() {
  local webhook_id=""
  local name=""
  local url_val=""
  local events=""
  local app_slugs=()
  local app_ids=()
  local build_statuses=()
  local filter_tags=()
  local body_file
  local has_fields=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; has_fields=1; shift 2 ;;
      --name=*) name=${1#*=}; has_fields=1; shift ;;
      --url) require_value "$1" "$2"; url_val="$2"; has_fields=1; shift 2 ;;
      --url=*) url_val=${1#*=}; has_fields=1; shift ;;
      --events) require_value "$1" "$2"; events="$2"; has_fields=1; shift 2 ;;
      --events=*) events=${1#*=}; has_fields=1; shift ;;
      --app) require_value "$1" "$2"; app_slugs+=("$2"); has_fields=1; shift 2 ;;
      --app=*) app_slugs+=("${1#*=}"); has_fields=1; shift ;;
      --app-id) require_value "$1" "$2"; app_ids+=("$2"); has_fields=1; shift 2 ;;
      --app-id=*) app_ids+=("${1#*=}"); has_fields=1; shift ;;
      --build-status) require_value "$1" "$2"; build_statuses+=("$2"); has_fields=1; shift 2 ;;
      --build-status=*) build_statuses+=("${1#*=}"); has_fields=1; shift ;;
      --tag) require_value "$1" "$2"; filter_tags+=("$2"); has_fields=1; shift 2 ;;
      --tag=*) filter_tags+=("${1#*=}"); has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl webhooks update <webhook-id> [--name NAME] [--url URL] [--events JSON] [filters]\n'
        printf '\nFilters match "webhooks create" and replace the stored filter set.\n'
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

  require_jq
  ensure_tmpdir

  local filters_json
  filters_json=$(_webhooks_filters_json \
    "$(_webhooks_join_array "${app_slugs[@]+"${app_slugs[@]}"}")" \
    "$(_webhooks_join_array "${app_ids[@]+"${app_ids[@]}"}")" \
    "$(_webhooks_join_array "${build_statuses[@]+"${build_statuses[@]}"}")" \
    "$(_webhooks_join_array "${filter_tags[@]+"${filter_tags[@]}"}")") || return 1

  body_file=$(new_temp_file webhooks-update) || return 1
  local jq_args=(-n)
  local jq_expr='{'
  local first=1
  if [ -n "$name" ]; then
    jq_args+=(--arg name "$name")
    jq_expr="$jq_expr name: \$name"
    first=0
  fi
  if [ -n "$url_val" ]; then
    jq_args+=(--arg url "$url_val")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr url: \$url"
    first=0
  fi
  if [ -n "$events" ]; then
    jq_args+=(--argjson events "$events")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr events: \$events"
    first=0
  fi
  if [ -n "$filters_json" ]; then
    jq_args+=(--argjson filters "$filters_json")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr filters: \$filters"
    first=0
  fi
  jq_expr="$jq_expr }"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" \
    || die "--events must be a JSON array"

  local path="webhooks/$webhook_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: PATCH\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    return 0
  fi

  api_patch "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","url":"url","events":"subscribed_events // [] | join(\", \")","enabled":"enabled","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
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
