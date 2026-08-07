#!/usr/bin/env bash

telemetry_usage() {
  cat <<'EOF'
Usage: appmctl [global options] telemetry <action> [options]

Actions:
  issues                    List grouped error issues for an app
  issue <issue-id>          Inspect an issue and selected occurrence
  resolve <issue-id>        Mark an issue as resolved
  reopen <issue-id>         Mark an issue as unresolved
  tags <issue-id>           Replace App Manager issue tags
  traces                    List traces for an app
  trace <trace-id>          Inspect a trace and selected event

List filters: --environment VALUE, --identifier UUID (repeatable), --search TEXT, --per-page N
Detail option: --event UUID
EOF
}

command_telemetry() {
  local action="${1:-}"; shift 2>/dev/null || true
  case "$action" in issues) _telemetry_list issues "$@" ;; traces) _telemetry_list traces "$@" ;; issue) _telemetry_show issues "${1:-}" "${@:2}" ;; trace) _telemetry_show traces "${1:-}" "${@:2}" ;; resolve) _telemetry_status resolved "${1:-}" "${@:2}" ;; reopen) _telemetry_status unresolved "${1:-}" "${@:2}" ;; tags) _telemetry_tags "${1:-}" "${@:2}" ;; -h|--help|"") telemetry_usage; [ -z "$action" ] && return 1; return 0 ;; *) die "unknown telemetry action: $action (use issues, issue, resolve, reopen, tags, traces, trace)" ;; esac
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

_telemetry_status() {
  local status="$1" id="$2"; shift 2; [ -n "$id" ] || die "issue ID is required"
  local app="${APP:-}" comment=""
  while [ "$#" -gt 0 ]; do case "$1" in --app) require_value "$1" "$2"; app="$2"; shift 2 ;; --app=*) app=${1#*=}; shift ;; --comment) require_value "$1" "$2"; comment="$2"; shift 2 ;; --comment=*) comment=${1#*=}; shift ;; --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl telemetry %s <issue-id> [--comment TEXT]\n' "$( [ "$status" = resolved ] && printf resolve || printf reopen )"; return 0 ;; *) die "unknown telemetry status option: $1" ;; esac; done
  APP="$app"; require_app; require_jq; ensure_tmpdir
  local path="apps/$APP/telemetry/issues/$id" body_file
  body_file=$(new_temp_file issue-status) || die "could not create status request"
  "$JQ_BIN" -n --arg status "$status" --arg comment "$comment" '{status:$status,comment:(if $comment == "" then null else $comment end)}' >"$body_file"
  if [ "$DRY_RUN" -eq 1 ]; then local url; require_config; url=$(build_url "$path") || return 1; printf 'dry-run: no request sent\nmethod: PATCH\nurl: %s\nbody: %s\n' "$url" "$(cat "$body_file")"; return; fi
  api_patch "$path" "$body_file" || return 1
  if [ "$PRETTY_JSON" -eq 1 ]; then emit_response; else printf 'Issue %s %s\n' "$id" "$( [ "$status" = resolved ] && printf resolved || printf reopened )"; fi
}

_telemetry_tags() {
  local id="$1"; shift; [ -n "$id" ] || die "issue ID is required"
  local app="${APP:-}" clear=0; local tag_specs=()
  while [ "$#" -gt 0 ]; do case "$1" in --app) require_value "$1" "$2"; app="$2"; shift 2 ;; --app=*) app=${1#*=}; shift ;; --tag) require_value "$1" "$2"; tag_specs+=("$2"); shift 2 ;; --tag=*) tag_specs+=("${1#*=}"); shift ;; --clear) clear=1; shift ;; --dry-run) DRY_RUN=1; shift ;; --json) PRETTY_JSON=1; shift ;; -h|--help) printf 'Usage: appmctl telemetry tags <issue-id> [--tag TEXT[:#HEX[:ANIMATION]] ... | --clear]\n'; return 0 ;; *) die "unknown telemetry tags option: $1" ;; esac; done
  APP="$app"; require_app; require_jq; ensure_tmpdir
  [ "$clear" -eq 0 ] || [ "${#tag_specs[@]}" -eq 0 ] || die "--clear cannot be combined with --tag"
  [ "$clear" -eq 1 ] || [ "${#tag_specs[@]}" -gt 0 ] || die "--tag or --clear is required"
  local tags_json='[]' body_file path="apps/$APP/telemetry/issues/$id/tags"
  if [ "$clear" -eq 0 ]; then tags_json=$(_telemetry_tags_json "${tag_specs[@]}") || return 1; fi
  body_file=$(new_temp_file issue-tags) || die "could not create tags request"
  "$JQ_BIN" -n --argjson tags "$tags_json" '{tags:$tags}' >"$body_file"
  if [ "$DRY_RUN" -eq 1 ]; then local url; require_config; url=$(build_url "$path") || return 1; printf 'dry-run: no request sent\nmethod: PATCH\nurl: %s\nbody: %s\n' "$url" "$(cat "$body_file")"; return; fi
  api_patch "$path" "$body_file" || return 1
  if [ "$PRETTY_JSON" -eq 1 ]; then emit_response; else printf 'Issue %s tags updated\n' "$id"; fi
}

_telemetry_tags_json() {
  local out='[]' spec text color animation extra
  for spec in "$@"; do
    IFS=':' read -r text color animation extra <<EOF
$spec
EOF
    [ -n "$text" ] || die "--tag requires a label"
    [ -z "$extra" ] || die "tag '$text' has too many ':' segments"
    if [ -n "$color" ]; then case "$color" in \#[0-9a-fA-F][0-9a-fA-F][0-9a-fA-F]|\#[0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F]) ;; *) die "tag '$text' has an invalid color '$color'" ;; esac; fi
    if [ -n "$animation" ]; then case "$animation" in pulse|ping|flash|tada|heartbeat) ;; *) die "tag '$text' has an invalid animation '$animation'" ;; esac; fi
    out=$("$JQ_BIN" -nc --argjson acc "$out" --arg text "$text" --arg color "$color" --arg animation "$animation" '$acc + [[ $text, (if $color == "" then null else $color end), (if $animation == "" then null else $animation end) ]]')
  done
  printf '%s' "$out"
}

_telemetry_encode() { "$JQ_BIN" -nr --arg value "$1" '$value|@uri'; }
_telemetry_dry() { local url; require_config; url=$(build_url "$1") || return 1; printf 'dry-run: no request sent\nmethod: GET\nurl: %s\n' "$url"; }
