#!/usr/bin/env bash

# Legacy "stream" command. Preserved for backward compatibility.

stream_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] stream --app APP --build-id ID [options] -- COMMAND [ARG ...]
  appmctl [global options] stream --app APP --build-id ID [options] --command COMMAND [ARG ...]

Runs COMMAND without eval, mirrors its combined stdout/stderr locally, and
uploads output to the manual build log while the command is running.

Options:
  --app APP                 App slug; defaults to APP_MANAGER_APP
  --build-id ID             Existing manual build UUID (required)
  --step NAME               Add visible start/finish separators to the log
  --chunk-bytes BYTES       Upload chunk size, 1..262144 (default: 65536)
  --manage-status           Set running before the command and completed/failed after
  --track                   Poll build status and abort if cancelled (uses APP_MANAGER_POLLING_INTERVAL)
  --dry-run                 Print the plan without running COMMAND or calling the API
  --command                 Treat all remaining arguments as COMMAND

The CLI exits with COMMAND's status. If COMMAND succeeds but a log/status
upload fails, the CLI exits non-zero. Use "sh -c" explicitly for pipes,
redirects, variable expansion, or other shell syntax.
EOF
}

stream_worker() {
  local log_file="$1"
  local done_file="$2"
  local log_url="$3"
  local chunk_bytes="$4"
  local cancelled_file="${5:-}"
  local offset=0
  local size=0
  local available=0
  local count=0
  local chunk_file

  while :; do
    if [ -n "$cancelled_file" ] && [ -f "$cancelled_file" ]; then
      return 1
    fi

    size=$(wc -c <"$log_file" | tr -d '[:space:]')
    [ -n "$size" ] || size=0

    if [ "$size" -gt "$offset" ]; then
      available=$((size - offset))
      count=$chunk_bytes
      if [ "$available" -lt "$count" ]; then
        count=$available
      fi

      chunk_file=$(new_temp_file log-chunk) || return 1
      if ! dd if="$log_file" of="$chunk_file" bs=1 skip="$offset" count="$count" 2>/dev/null; then
        say_error "could not read captured command output"
        return 1
      fi
      if ! post_log_file "$log_url" "$chunk_file"; then
        return 1
      fi
      offset=$((offset + count))
      continue
    fi

    if [ -f "$done_file" ]; then
      return 0
    fi
    sleep 1
  done
}

track_worker() {
  local build_url="$1"
  local cancelled_file="$2"
  local interval="$3"
  local status

  while :; do
    sleep "$interval"

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
}

update_build_status() {
  local build_url="$1"
  local status="$2"
  local body_file

  body_file=$(new_temp_file status) || return 1
  printf '{"status":"%s"}' "$status" >"$body_file"
  send_json_file PATCH "$build_url" "$body_file"
}

print_stream_plan() {
  local app="$1"
  local build_id="$2"
  local step="$3"
  local chunk_bytes="$4"
  local manage_status="$5"
  local track="$6"
  shift 6

  printf 'dry-run: command not run and no request sent\n'
  printf 'build: %s/apps/%s/builds/%s\n' "$TEAM" "$app" "$build_id"
  if [ -n "$step" ]; then
    printf 'step: %s\n' "$step"
  fi
  printf 'chunk-bytes: %s\n' "$chunk_bytes"
  printf 'manage-status: %s\n' "$manage_status"
  printf 'track: %s\n' "$track"
  printf 'command:'
  printf ' %q' "$@"
  printf '\n'
}

command_stream() {
  local app="${APP:-}"
  local build_id=""
  local step=""
  local chunk_bytes=65536
  local manage_status=0
  local track=0
  local stream_command=()
  local api_root
  local build_url
  local log_url
  local stream_dir
  local log_file
  local done_file
  local cancelled_file=""
  local worker_pid
  local track_pid=""
  local command_pid
  local command_status
  local tee_status
  local worker_status
  local final_status
  local status_error=0
  local marker
  local pipeline_status=()
  local build_was_cancelled=0

  if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
    stream_usage
    return 0
  fi

  [ -z "$OUTPUT_FILE" ] || die "--output is only supported by the request command"
  [ "$PRETTY_JSON" -eq 0 ] || die "--json is only supported by the request command"

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app)
        require_value "$1" "$2"
        app="$2"
        shift 2
        ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id)
        require_value "$1" "$2"
        build_id="$2"
        shift 2
        ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --step)
        require_value "$1" "$2"
        step="$2"
        shift 2
        ;;
      --step=*) step=${1#*=}; shift ;;
      --chunk-bytes)
        require_value "$1" "$2"
        chunk_bytes="$2"
        shift 2
        ;;
      --chunk-bytes=*) chunk_bytes=${1#*=}; shift ;;
      --manage-status) manage_status=1; shift ;;
      --track) track=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --command)
        shift
        stream_command=("$@")
        set --
        ;;
      --)
        shift
        stream_command=("$@")
        set --
        ;;
      -h|--help) stream_usage; return 0 ;;
      -*) die "unknown stream option: $1" ;;
      *) die "unexpected stream argument '$1'; put the command after -- or --command" ;;
    esac
  done

  require_config
  [ -n "$app" ] || die "stream requires --app or APP_MANAGER_APP"
  [ -n "$build_id" ] || die "stream requires --build-id"
  [ "${#stream_command[@]}" -gt 0 ] || die "stream requires a command after -- or --command"
  validate_slug "app" "$app"
  validate_slug "build ID" "$build_id"
  case "$chunk_bytes" in
    ''|*[!0-9]*) die "--chunk-bytes must be an integer from 1 to 262144" ;;
  esac
  if [ "$chunk_bytes" -lt 1 ] || [ "$chunk_bytes" -gt 262144 ]; then
    die "--chunk-bytes must be an integer from 1 to 262144"
  fi
  if [ "$track" -eq 1 ]; then
    validate_polling_interval
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    print_stream_plan "$app" "$build_id" "$step" "$chunk_bytes" "$manage_status" "$track" "${stream_command[@]}"
    return 0
  fi

  require_curl
  require_jq
  ensure_tmpdir
  api_root=$(normalize_api_root) || return 1
  build_url="$api_root/$TEAM/apps/$app/builds/$build_id"
  log_url="$build_url/logs"

  if [ "$manage_status" -eq 1 ]; then
    update_build_status "$build_url" running || return 1
  fi

  if [ -n "$step" ]; then
    printf -v marker '\n--- step: %s ---\n' "$step"
    post_log_text "$log_url" "$marker" || return 1
  fi

  ensure_tmpdir
  stream_dir=$(mktemp -d "$APPMCTL_TMPDIR/stream.XXXXXX") || die "could not create stream state"
  log_file="$stream_dir/command.log"
  done_file="$stream_dir/done"
  if [ "$track" -eq 1 ]; then
    cancelled_file="$stream_dir/cancelled"
  fi
  : >"$log_file"

  (
    trap - EXIT INT TERM
    stream_worker "$log_file" "$done_file" "$log_url" "$chunk_bytes" "$cancelled_file"
  ) &
  worker_pid=$!

  if [ "$track" -eq 1 ]; then
    (
      trap - EXIT INT TERM
      track_worker "$build_url" "$cancelled_file" "$POLLING_INTERVAL"
    ) &
    track_pid=$!
  fi

  if [ "$track" -eq 1 ]; then
    local exit_file="$stream_dir/exit_code"
    (
      "${stream_command[@]}" 2>&1
      echo $? > "$exit_file"
    ) | tee -a "$log_file" &
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

    if [ "$build_was_cancelled" -eq 0 ] && [ -f "$exit_file" ]; then
      command_status=$(cat "$exit_file")
    else
      command_status=130
    fi
    tee_status=0
  else
    "${stream_command[@]}" 2>&1 | tee -a "$log_file"
    pipeline_status=("${PIPESTATUS[@]}")
    command_status=${pipeline_status[0]}
    tee_status=${pipeline_status[1]}
  fi
  : >"$done_file"

  wait "$worker_pid"
  worker_status=$?

  if [ -n "$track_pid" ]; then
    : > "$cancelled_file" 2>/dev/null || true
    kill "$track_pid" 2>/dev/null || true
    wait "$track_pid" 2>/dev/null || true
  fi

  if [ "$build_was_cancelled" -eq 0 ] && [ "${tee_status:-0}" -ne 0 ]; then
    say_error "could not capture command output (tee exit ${tee_status:-0})"
    worker_status=1
  fi

  if [ -n "$step" ] && [ "$worker_status" -eq 0 ] && [ "$build_was_cancelled" -eq 0 ]; then
    printf -v marker '%s\n' "--- step: $step finished (exit $command_status) ---"
    post_log_text "$log_url" "$marker" || worker_status=1
  fi

  if [ "$manage_status" -eq 1 ] && [ "$build_was_cancelled" -eq 0 ]; then
    if [ "$command_status" -eq 0 ] && [ "$worker_status" -eq 0 ]; then
      final_status=completed
    else
      final_status=failed
    fi
    update_build_status "$build_url" "$final_status" || status_error=1
  fi

  if [ "$build_was_cancelled" -eq 1 ]; then
    say_error "build was cancelled, aborting"
    return 130
  fi

  if [ "$command_status" -ne 0 ]; then
    return "$command_status"
  fi
  if [ "$worker_status" -ne 0 ] || [ "$status_error" -ne 0 ]; then
    return 1
  fi
  return 0
}
