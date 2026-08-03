#!/usr/bin/env bash

# "pipelines" subcommands: list, get, create, run, inputs, update, delete.

pipelines_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] pipelines <action> [options]

Actions:
  list                List pipelines for an integration
  get                 Show pipeline details
  create              Create a pipeline
  update              Update a pipeline
  delete              Delete a pipeline
  run                 Trigger a pipeline run
  inputs              Get required inputs for a pipeline

Options:
  --integration-id ID Integration ID (required for most actions)
  --pipeline-id ID    Pipeline ID (required for get, update, delete, run, inputs)
  --name NAME         Pipeline name (create, update)
  --config JSON       Pipeline configuration (create, update)
  --inputs JSON       Run inputs (run)

Run "appmctl pipelines <action> --help" for action-specific help.
EOF
}

command_pipelines() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _pipelines_list "$@" ;;
    get) _pipelines_get "$@" ;;
    create) _pipelines_create "$@" ;;
    update) _pipelines_update "$@" ;;
    delete) _pipelines_delete "$@" ;;
    run) _pipelines_run "$@" ;;
    inputs) _pipelines_inputs "$@" ;;
    -h|--help|"") pipelines_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown pipelines action: $action (use list, get, create, update, delete, run, inputs)" ;;
  esac
}

_pipelines_list() {
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
        printf 'Usage: appmctl pipelines list --integration-id ID\n'
        return 0
        ;;
      *) die "unknown pipelines list option: $1" ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  local path="integrations/$integration_id/pipelines"

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
  local fmt='{"type":"table","fields":["id","name","repository_full_name","workflow_name","enabled"],"headers":["id","name","repository","workflow","enabled"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_pipelines_get() {
  local integration_id=""
  local pipeline_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines get --integration-id ID <pipeline-id>\n'
        return 0
        ;;
      -*) die "unknown pipelines get option: $1" ;;
      *)
        [ -z "$pipeline_id" ] || die "unexpected argument: $1"
        pipeline_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$pipeline_id" ] || die "pipeline ID is required"

  local path="integrations/$integration_id/pipelines/$pipeline_id"

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
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","enabled":"enabled","profile_type":"profile_type","reporting_mode":"reporting_mode","repository":"repository_full_name","workflow":"workflow_name","workflow_path":"workflow_path","default_ref":"default_ref","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_pipelines_create() {
  local integration_id=""
  local name=""
  local config=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --config) require_value "$1" "$2"; config="$2"; shift 2 ;;
      --config=*) config=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines create --integration-id ID --name NAME [--config JSON]\n'
        return 0
        ;;
      *) die "unknown pipelines create option: $1" ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$name" ] || die "--name is required"

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  if [ -n "$config" ]; then
    "$JQ_BIN" -n --arg n "$name" --argjson c "$config" '{name: $n, config: $c}' >"$body_file"
  else
    "$JQ_BIN" -n --arg n "$name" '{name: $n}' >"$body_file"
  fi

  local path="integrations/$integration_id/pipelines"

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
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_pipelines_update() {
  local integration_id=""
  local pipeline_id=""
  local name=""
  local config=""
  local body_file
  local has_fields=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; has_fields=1; shift 2 ;;
      --name=*) name=${1#*=}; has_fields=1; shift ;;
      --config) require_value "$1" "$2"; config="$2"; has_fields=1; shift 2 ;;
      --config=*) config=${1#*=}; has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines update --integration-id ID <pipeline-id> [--name NAME] [--config JSON]\n'
        return 0
        ;;
      -*) die "unknown pipelines update option: $1" ;;
      *)
        [ -z "$pipeline_id" ] || die "unexpected argument: $1"
        pipeline_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$pipeline_id" ] || die "pipeline ID is required"
  [ "$has_fields" -eq 1 ] || die "at least one field to update is required"

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
  if [ -n "$config" ]; then
    jq_args+=(--argjson c "$config")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr config: \$c"
    first=0
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  local path="integrations/$integration_id/pipelines/$pipeline_id"

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
  printf 'Pipeline %s updated\n' "$pipeline_id"
}

_pipelines_delete() {
  local integration_id=""
  local pipeline_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines delete --integration-id ID <pipeline-id>\n'
        return 0
        ;;
      -*) die "unknown pipelines delete option: $1" ;;
      *)
        [ -z "$pipeline_id" ] || die "unexpected argument: $1"
        pipeline_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$pipeline_id" ] || die "pipeline ID is required"

  local path="integrations/$integration_id/pipelines/$pipeline_id"

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
  printf 'Pipeline %s deleted\n' "$pipeline_id"
}

_pipelines_run() {
  local integration_id=""
  local pipeline_id=""
  local inputs=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --inputs) require_value "$1" "$2"; inputs="$2"; shift 2 ;;
      --inputs=*) inputs=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines run --integration-id ID <pipeline-id> [--inputs JSON]\n'
        return 0
        ;;
      -*) die "unknown pipelines run option: $1" ;;
      *)
        [ -z "$pipeline_id" ] || die "unexpected argument: $1"
        pipeline_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$pipeline_id" ] || die "pipeline ID is required"

  local path="integrations/$integration_id/pipelines/$pipeline_id/runs"

  body_file=$(mktemp "${TMPDIR:-/tmp}/appmctl-body.XXXXXX") || die "could not create temp file"
  if [ -n "$inputs" ]; then
    "$JQ_BIN" -n --argjson i "$inputs" '{inputs: $i}' >"$body_file"
  else
    printf '{}' >"$body_file"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    [ -n "$inputs" ] && printf 'inputs: %s\n' "$inputs"
    rm -f -- "$body_file"
    return 0
  fi

  api_post "$path" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  printf 'Pipeline run triggered\n'
}

_pipelines_inputs() {
  local integration_id=""
  local pipeline_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl pipelines inputs --integration-id ID <pipeline-id>\n'
        return 0
        ;;
      -*) die "unknown pipelines inputs option: $1" ;;
      *)
        [ -z "$pipeline_id" ] || die "unexpected argument: $1"
        pipeline_id="$1"; shift ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$pipeline_id" ] || die "pipeline ID is required"

  local path="integrations/$integration_id/pipelines/$pipeline_id/inputs"

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
