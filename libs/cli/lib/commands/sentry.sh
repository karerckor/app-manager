#!/usr/bin/env bash

sentry_usage() {
  cat <<'EOF'
Usage: appmctl [global options] sentry <action> [options]

Actions:
  list                         List routed Sentry DSNs
  get <dsn-id>                 Show one routed DSN
  create --config FILE         Create a routed DSN from JSON
  update <dsn-id> --config FILE Replace a routed DSN configuration
  delete <dsn-id>              Delete a routed DSN
EOF
}

command_sentry() {
  local action="${1:-}"; shift 2>/dev/null || true
  case "$action" in
    list) _sentry_list "$@" ;; get) _sentry_get "$@" ;; create) _sentry_write POST "" "$@" ;;
    update) local id="${1:-}"; [ -n "$id" ] || die "DSN ID is required"; shift; _sentry_write PATCH "$id" "$@" ;;
    delete) _sentry_delete "$@" ;;
    -h|--help|"") sentry_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown sentry action: $action (use list, get, create, update, delete)" ;;
  esac
}

_sentry_common_options() {
  return 0
}

_sentry_list() {
  while [ "$#" -gt 0 ]; do case "$1" in --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl sentry list\n'; return 0 ;; *) die "unknown sentry list option: $1" ;; esac; done
  if [ "$DRY_RUN" -eq 1 ]; then _sentry_dry GET "integrations/sentry"; return; fi
  api_get "integrations/sentry" || return 1
  emit_formatted_response "$LAST_RESPONSE_FILE" '{"type":"table","fields":["id","name","routing_method","enabled","forward_enabled","last_event_at"],"headers":["id","name","routing","enabled","forward","last_event_at"]}'
}

_sentry_get() {
  local id="${1:-}"; [ -n "$id" ] || die "DSN ID is required"; shift 2>/dev/null || true
  while [ "$#" -gt 0 ]; do case "$1" in --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl sentry get <dsn-id>\n'; return 0 ;; *) die "unknown sentry get option: $1" ;; esac; done
  if [ "$DRY_RUN" -eq 1 ]; then _sentry_dry GET "integrations/sentry/$id"; return; fi
  api_get "integrations/sentry/$id" || return 1
  emit_response
}

_sentry_write() {
  local method="$1" id="$2"; shift 2
  local config=""
  while [ "$#" -gt 0 ]; do case "$1" in --config) require_value "$1" "$2"; config="$2"; shift 2 ;; --config=*) config=${1#*=}; shift ;; --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl sentry %s %s--config FILE\n' "$( [ "$method" = POST ] && printf create || printf update )" "$( [ -n "$id" ] && printf '<dsn-id> ' )"; return 0 ;; *) die "unknown sentry option: $1" ;; esac; done
  [ -n "$config" ] || die "--config is required"; [ -f "$config" ] || die "config file does not exist: $config"
  require_jq; "$JQ_BIN" -e 'type == "object"' "$config" >/dev/null || die "--config must contain a JSON object"
  local path="integrations/sentry${id:+/$id}"
  if [ "$DRY_RUN" -eq 1 ]; then _sentry_dry "$method" "$path"; printf 'body: %s\n' "$(cat "$config")"; return; fi
  if [ "$method" = POST ]; then api_post "$path" "$config" || return 1; else api_patch "$path" "$config" || return 1; fi
  emit_response
}

_sentry_delete() {
  local id="${1:-}"; [ -n "$id" ] || die "DSN ID is required"; shift 2>/dev/null || true
  while [ "$#" -gt 0 ]; do case "$1" in --dry-run) DRY_RUN=1; shift ;; -h|--help) printf 'Usage: appmctl sentry delete <dsn-id>\n'; return 0 ;; *) die "unknown sentry delete option: $1" ;; esac; done
  if [ "$DRY_RUN" -eq 1 ]; then _sentry_dry DELETE "integrations/sentry/$id"; return; fi
  api_delete "integrations/sentry/$id" || return 1; printf 'Sentry DSN %s deleted\n' "$id"
}

_sentry_dry() { local url; require_config; url=$(build_url "$2") || return 1; printf 'dry-run: no request sent\nmethod: %s\nurl: %s\n' "$1" "$url"; }
