#!/usr/bin/env bash

telemetry_usage() {
  cat <<'EOF'
Usage: appmctl [global options] telemetry <action> [options]

Actions:
  issues                    List grouped error issues for an app
  issue <issue-id>          Inspect an issue and selected occurrence
  traces                    List traces for an app
  trace <trace-id>          Inspect a trace and selected event

List filters: --environment VALUE, --identifier UUID (repeatable), --search TEXT, --per-page N
Detail option: --event UUID
EOF
}

command_telemetry() {
  local action="${1:-}"; shift 2>/dev/null || true
  case "$action" in issues) _telemetry_list issues "$@" ;; traces) _telemetry_list traces "$@" ;; issue) _telemetry_show issues "${1:-}" "${@:2}" ;; trace) _telemetry_show traces "${1:-}" "${@:2}" ;; -h|--help|"") telemetry_usage; [ -z "$action" ] && return 1; return 0 ;; *) die "unknown telemetry action: $action (use issues, issue, traces, trace)" ;; esac
}

_telemetry_list() {
  local kind="$1"; shift; local app="${APP:-}" environment="" search="" per_page=""; local identifiers=()
  while [ "$#" -gt 0 ]; do case "$1" in --app) require_value "$1" "$2"; app="$2"; shift 2 ;; --app=*) app=${1#*=}; shift ;; --environment) require_value "$1" "$2"; environment="$2"; shift 2 ;; --environment=*) environment=${1#*=}; shift ;; --identifier) require_value "$1" "$2"; identifiers+=("$2"); shift 2 ;; --identifier=*) identifiers+=("${1#*=}"); shift ;; --search) require_value "$1" "$2"; search="$2"; shift 2 ;; --search=*) search=${1#*=}; shift ;; --per-page) require_value "$1" "$2"; per_page="$2"; shift 2 ;; --per-page=*) per_page=${1#*=}; shift ;; --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl telemetry %s [filters]\n' "$kind"; return 0 ;; *) die "unknown telemetry $kind option: $1" ;; esac; done
  APP="$app"; require_app; require_jq
  case "$per_page" in ''|*[!0-9]*) [ -z "$per_page" ] || die "--per-page must be a positive integer" ;; esac
  local query="" pair encoded value
  for pair in "environment:$environment" "search:$search" "per_page:$per_page"; do value=${pair#*:}; [ -z "$value" ] && continue; encoded=$(_telemetry_encode "$value"); query="${query}${query:+&}${pair%%:*}=$encoded"; done
  for value in "${identifiers[@]+"${identifiers[@]}"}"; do encoded=$(_telemetry_encode "$value"); query="${query}${query:+&}identifiers%5B%5D=$encoded"; done
  local path="apps/$APP/telemetry/$kind${query:+?$query}"
  if [ "$DRY_RUN" -eq 1 ]; then _telemetry_dry "$path"; return; fi
  api_get "$path" || return 1
  if [ "$kind" = issues ]; then emit_formatted_response "$LAST_RESPONSE_FILE" '{"type":"table","fields":["id","title","events_count","status","last_seen_at"],"headers":["id","title","events","status","last_seen_at"]}'; else emit_formatted_response "$LAST_RESPONSE_FILE" '{"type":"table","fields":["trace_id","title","environment","release","occurred_at"],"headers":["trace_id","title","environment","release","occurred_at"]}'; fi
}

_telemetry_show() {
  local kind="$1" id="$2"; shift 2; [ -n "$id" ] || die "${kind%?} ID is required"
  local app="${APP:-}" event=""
  while [ "$#" -gt 0 ]; do case "$1" in --app) require_value "$1" "$2"; app="$2"; shift 2 ;; --app=*) app=${1#*=}; shift ;; --event) require_value "$1" "$2"; event="$2"; shift 2 ;; --event=*) event=${1#*=}; shift ;; --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl telemetry %s <%s-id> [--event UUID]\n' "${kind%?}" "${kind%?}"; return 0 ;; *) die "unknown telemetry detail option: $1" ;; esac; done
  APP="$app"; require_app; require_jq
  local path="apps/$APP/telemetry/$kind/$id"; [ -n "$event" ] && path="$path?event=$(_telemetry_encode "$event")"
  if [ "$DRY_RUN" -eq 1 ]; then _telemetry_dry "$path"; return; fi
  api_get "$path" || return 1; emit_response
}

_telemetry_encode() { "$JQ_BIN" -nr --arg value "$1" '$value|@uri'; }
_telemetry_dry() { local url; require_config; url=$(build_url "$1") || return 1; printf 'dry-run: no request sent\nmethod: GET\nurl: %s\n' "$url"; }
