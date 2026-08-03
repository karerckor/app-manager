#!/usr/bin/env bash

# "builds" subcommands: list, get, create, cancel, patch.

# Turn repeated --tag specs ("name" or "name:#rrggbb") into the [[text, color]]
# tuples the API expects. Colorless tags carry a null color.
_builds_tags_json() {
  local out='[]'
  local spec text color

  for spec in "$@"; do
    case "$spec" in
      *:#*) text="${spec%:#*}"; color="#${spec##*:#}" ;;
      *) text="$spec"; color="" ;;
    esac

    [ -n "$text" ] || die "--tag requires a name (use NAME or NAME:#RRGGBB)"

    if [ -n "$color" ]; then
      case "$color" in
        \#[0-9a-fA-F][0-9a-fA-F][0-9a-fA-F]) ;;
        \#[0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F][0-9a-fA-F]) ;;
        *) die "tag '$text' has an invalid color '$color' (expected a hex value like #3b82f6)" ;;
      esac
      out=$("$JQ_BIN" -nc --argjson acc "$out" --arg t "$text" --arg c "$color" '$acc + [[$t, $c]]')
    else
      out=$("$JQ_BIN" -nc --argjson acc "$out" --arg t "$text" '$acc + [[$t, null]]')
    fi
  done

  printf '%s' "$out"
}

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
  wait                Poll build status until terminal
  when-running        Run a command only if build is active

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --build-id ID       Build ID (required for get, cancel, patch)
  --status STATUS     Filter by status (list) or set status (patch)
  --profile TYPE      Build profile type (create)
  --request-uuid UUID Idempotency key (create)
  --context JSON      Build context metadata (create)
  --page N            Page number (list)
  --timeout SECONDS   Maximum wait time (wait, when-running)

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

Create a build. Defaults to manual reporting mode.

Options:
  --profile TYPE           Build profile (e.g. production, staging, appstore)
  --request-uuid UUID      Idempotency key for CI retries
  --context JSON           Inline JSON context/metadata
  --reporting-mode MODE    Build reporting mode: manual (default) or eas
  --expo-build-id ID       Expo/EAS build ID (required when reporting-mode=eas)
  --app-identifier-id UUID Target app identifier ID
  --tag NAME[:#HEX]        Attach a tag; repeatable (e.g. --tag ios:#3b82f6)

Examples:
  appmctl builds create --app customer-app --profile production
  appmctl builds create --profile staging --request-uuid "$(uuidgen)"
  appmctl builds create --profile production --context '{"runner":"github"}'
  appmctl builds create --profile production --reporting-mode eas --expo-build-id "abc-123"
  appmctl builds create --profile production --tag ios:#3b82f6 --tag 1.0.0
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
  appmctl builds patch [--app APP] <build-id> [--status STATUS] [--tag NAME[:#HEX]]

Update a build's status and/or its tags. At least one of --status or --tag is
required. Status transitions apply to manually reported builds only; tags can be
set on any build.

Options:
  --status STATUS     New status: running, completed, failed, cancelled
  --tag NAME[:#HEX]   Replace the tag set; repeatable (e.g. --tag ios:#3b82f6)

Examples:
  appmctl builds patch bld-abc123 --status completed
  appmctl builds patch bld-abc123 --status failed
  appmctl builds patch bld-abc123 --tag ios:#3b82f6 --tag 1.0.0
EOF
}

builds_wait_usage() {
  cat <<'EOF'
Usage:
  appmctl builds wait [--app APP] <build-id> [--timeout SECONDS]

Poll build status until it reaches a terminal state (completed, failed, cancelled).

Exit codes:
  0    Build completed successfully
  1    Build failed
  130  Build was cancelled
  124  Timeout exceeded (if --timeout specified)

Options:
  --timeout SECONDS   Maximum time to wait (default: unlimited)

Examples:
  appmctl builds wait bld-abc123
  appmctl builds wait bld-abc123 --timeout 3600
EOF
}

builds_when_running_usage() {
  cat <<'EOF'
Usage:
  appmctl builds when-running [--app APP] <build-id> [--timeout SECONDS] -- COMMAND [ARG ...]

Run COMMAND only if the build is currently active (pending or running).
While COMMAND runs, polls build status and aborts if cancelled.

Exit codes:
  0    Command completed successfully
  1    Command failed or build is not active
  130  Build was cancelled during execution

Options:
  --timeout SECONDS   Maximum time to wait for build to become active

Examples:
  appmctl builds when-running bld-abc123 -- npm run build
  appmctl builds when-running bld-abc123 --timeout 300 -- make deploy
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
    wait) _builds_wait "$@" ;;
    when-running) _builds_when_running "$@" ;;
    -h|--help|"") builds_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown builds action: $action (use list, get, create, cancel, patch, wait, when-running)" ;;
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
  local reporting_mode="manual"
  local expo_build_id=""
  local app_identifier_id=""
  local tag_specs=()
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
      --reporting-mode) require_value "$1" "$2"; reporting_mode="$2"; shift 2 ;;
      --reporting-mode=*) reporting_mode=${1#*=}; shift ;;
      --expo-build-id) require_value "$1" "$2"; expo_build_id="$2"; shift 2 ;;
      --expo-build-id=*) expo_build_id=${1#*=}; shift ;;
      --app-identifier-id) require_value "$1" "$2"; app_identifier_id="$2"; shift 2 ;;
      --app-identifier-id=*) app_identifier_id=${1#*=}; shift ;;
      --tag) require_value "$1" "$2"; tag_specs+=("$2"); shift 2 ;;
      --tag=*) tag_specs+=("${1#*=}"); shift ;;
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
  case "$reporting_mode" in
    manual|eas) ;;
    *) die "--reporting-mode must be 'manual' or 'eas'" ;;
  esac
  if [ "$reporting_mode" = "eas" ] && [ -z "$expo_build_id" ]; then
    die "--expo-build-id is required when --reporting-mode=eas"
  fi

  # Validate tags before anything else is allocated. Assign separately:
  # `local x=$(...)` would swallow the helper's exit status.
  local tags_json=""
  if [ "${#tag_specs[@]}" -gt 0 ]; then
    tags_json=$(_builds_tags_json "${tag_specs[@]}") || return 1
  fi

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

  local jq_args=(--arg pid "$request_uuid" --arg pf "$profile" --arg rm "$reporting_mode")
  local jq_filter='{request_uuid: $pid, profile_type: $pf, reporting_mode: $rm'

  if [ -n "$expo_build_id" ]; then
    jq_args+=(--arg eid "$expo_build_id")
    jq_filter="$jq_filter, expo_build_id: \$eid"
  fi

  if [ -n "$app_identifier_id" ]; then
    jq_args+=(--arg iid "$app_identifier_id")
    jq_filter="$jq_filter, app_identifier_id: \$iid"
  fi

  if [ -n "$context" ]; then
    jq_args+=(--argjson ctx "$context")
    jq_filter="$jq_filter, context: \$ctx"
  fi

  if [ -n "$tags_json" ]; then
    jq_args+=(--argjson tags "$tags_json")
    jq_filter="$jq_filter, tags: \$tags"
  fi

  jq_filter="$jq_filter}"
  "$JQ_BIN" -n "${jq_args[@]}" "$jq_filter" >"$body_file"

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
  local fmt='{"type":"resource","fields":{"id":"id","status":"status","profile":"profile_type","reporting_mode":"reporting_mode","request_uuid":"request_uuid","created_at":"created_at"}}'
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
  local tag_specs=()

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --status) require_value "$1" "$2"; new_status="$2"; shift 2 ;;
      --status=*) new_status=${1#*=}; shift ;;
      --tag) require_value "$1" "$2"; tag_specs+=("$2"); shift 2 ;;
      --tag=*) tag_specs+=("${1#*=}"); shift ;;
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
  if [ -z "$new_status" ] && [ "${#tag_specs[@]}" -eq 0 ]; then
    die "--status or --tag is required"
  fi
  validate_slug "build ID" "$build_id"

  # Validate tags before anything else is allocated. Assign separately:
  # `local x=$(...)` would swallow the helper's exit status.
  local tags_json=""
  if [ "${#tag_specs[@]}" -gt 0 ]; then
    tags_json=$(_builds_tags_json "${tag_specs[@]}") || return 1
  fi

  local path="apps/$APP/builds/$build_id"
  local body_file
  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"

  local jq_args=()
  local jq_filter='{'

  if [ -n "$new_status" ]; then
    jq_args+=(--arg st "$new_status")
    jq_filter="${jq_filter}status: \$st"
  fi

  if [ -n "$tags_json" ]; then
    jq_args+=(--argjson tags "$tags_json")
    [ -n "$new_status" ] && jq_filter="$jq_filter, "
    jq_filter="${jq_filter}tags: \$tags"
  fi

  jq_filter="$jq_filter}"
  "$JQ_BIN" -nc "${jq_args[@]}" "$jq_filter" >"$body_file"

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
  if [ -n "$new_status" ]; then
    printf 'Build %s updated to %s\n' "$build_id" "$new_status"
  else
    printf 'Build %s tags updated\n' "$build_id"
  fi
}

_builds_wait() {
  local app="${APP:-}"
  local build_id=""
  local timeout=""
  local start_time
  local elapsed
  local status
  local api_root
  local build_url

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --timeout) require_value "$1" "$2"; timeout="$2"; shift 2 ;;
      --timeout=*) timeout=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      -h|--help) builds_wait_usage; return 0 ;;
      -*) die "unknown builds wait option: $1" ;;
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
  validate_polling_interval

  if [ -n "$timeout" ]; then
    case "$timeout" in
      ''|*[!0-9]*) die "--timeout must be a positive integer" ;;
    esac
    if [ "$timeout" -lt 1 ]; then
      die "--timeout must be a positive integer"
    fi
  fi

  local path="apps/$APP/builds/$build_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET (polling)\n'
    printf 'url: %s\n' "$url"
    printf 'interval: %s seconds\n' "$POLLING_INTERVAL"
    if [ -n "$timeout" ]; then
      printf 'timeout: %s seconds\n' "$timeout"
    fi
    return 0
  fi

  require_config
  require_curl
  require_jq
  ensure_tmpdir
  api_root=$(normalize_api_root) || return 1
  build_url="$api_root/$TEAM/apps/$APP/builds/$build_id"

  start_time=$(date +%s)

  while :; do
    status=$(fetch_build_status "$build_url") || {
      sleep "$POLLING_INTERVAL"
      continue
    }

    case "$status" in
      completed)
        printf 'Build %s completed\n' "$build_id"
        return 0
        ;;
      failed)
        printf 'Build %s failed\n' "$build_id"
        return 1
        ;;
      cancelled)
        printf 'Build %s cancelled\n' "$build_id"
        return 130
        ;;
    esac

    if [ -n "$timeout" ]; then
      elapsed=$(( $(date +%s) - start_time ))
      if [ "$elapsed" -ge "$timeout" ]; then
        say_error "timeout exceeded after ${elapsed}s (last status: $status)"
        return 124
      fi
    fi

    sleep "$POLLING_INTERVAL"
  done
}

_builds_when_running() {
  local app="${APP:-}"
  local build_id=""
  local timeout=""
  local run_command=()
  local start_time
  local elapsed
  local status
  local api_root
  local build_url
  local stream_dir
  local cancelled_file
  local track_pid=""
  local command_pid
  local exit_file
  local command_status
  local build_was_cancelled=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --timeout) require_value "$1" "$2"; timeout="$2"; shift 2 ;;
      --timeout=*) timeout=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --)
        shift
        run_command=("$@")
        set --
        ;;
      --command)
        shift
        run_command=("$@")
        set --
        ;;
      -h|--help) builds_when_running_usage; return 0 ;;
      -*) die "unknown builds when-running option: $1" ;;
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
  [ "${#run_command[@]}" -gt 0 ] || die "when-running requires a command after -- or --command"
  validate_slug "build ID" "$build_id"
  validate_polling_interval

  if [ -n "$timeout" ]; then
    case "$timeout" in
      ''|*[!0-9]*) die "--timeout must be a positive integer" ;;
    esac
    if [ "$timeout" -lt 1 ]; then
      die "--timeout must be a positive integer"
    fi
  fi

  local path="apps/$APP/builds/$build_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET (check + polling)\n'
    printf 'url: %s\n' "$url"
    printf 'interval: %s seconds\n' "$POLLING_INTERVAL"
    if [ -n "$timeout" ]; then
      printf 'timeout: %s seconds\n' "$timeout"
    fi
    printf 'command:'
    printf ' %q' "${run_command[@]}"
    printf '\n'
    return 0
  fi

  require_config
  require_curl
  require_jq
  ensure_tmpdir
  api_root=$(normalize_api_root) || return 1
  build_url="$api_root/$TEAM/apps/$APP/builds/$build_id"

  status=$(fetch_build_status "$build_url") || {
    say_error "could not fetch build status"
    return 1
  }

  case "$status" in
    completed|failed|cancelled)
      printf 'Build %s is already %s, not running command\n' "$build_id" "$status"
      return 1
      ;;
    pending|running|cancel_requested)
      printf 'Build %s is %s, running command\n' "$build_id" "$status"
      ;;
    *)
      say_error "unknown build status: $status"
      return 1
      ;;
  esac

  stream_dir=$(mktemp -d "$APPMCTL_TMPDIR/when-running.XXXXXX") || die "could not create temp directory"
  cancelled_file="$stream_dir/cancelled"
  exit_file="$stream_dir/exit_code"

  (
    trap - EXIT INT TERM
    while :; do
      sleep "$POLLING_INTERVAL"

      if [ -f "$cancelled_file" ]; then
        return 0
      fi

      status=$(fetch_build_status "$build_url") || continue

      case "$status" in
        cancelled|cancel_requested|completed|failed)
          : > "$cancelled_file"
          return 0
          ;;
      esac
    done
  ) &
  track_pid=$!

  (
    "${run_command[@]}" 2>&1
    echo $? > "$exit_file"
  ) &
  command_pid=$!

  while kill -0 "$command_pid" 2>/dev/null; do
    if [ -f "$cancelled_file" ]; then
      build_was_cancelled=1
      kill -TERM "$command_pid" 2>/dev/null
      sleep 0.5
      kill -KILL "$command_pid" 2>/dev/null
      break
    fi
    sleep 0.5
  done

  wait "$command_pid" 2>/dev/null

  : > "$cancelled_file" 2>/dev/null || true
  kill "$track_pid" 2>/dev/null || true
  wait "$track_pid" 2>/dev/null || true

  if [ "$build_was_cancelled" -eq 1 ]; then
    say_error "build was cancelled, aborting"
    return 130
  fi

  if [ -f "$exit_file" ]; then
    command_status=$(cat "$exit_file")
    return "$command_status"
  fi

  return 1
}
