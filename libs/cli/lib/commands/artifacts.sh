#!/usr/bin/env bash

# "artifacts" subcommands: list, get, upload, delete.

artifacts_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] artifacts <action> [options]

Actions:
  list                List artifacts for a build
  get                 Download an artifact
  upload              Upload a build artifact
  delete              Delete an artifact

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --build-id ID       Build ID (required)
  --artifact-id ID    Artifact ID (required for get, delete)
  --name NAME         Artifact name (upload)
  --metadata JSON     Artifact metadata (upload)
  --file FILE         File to upload (upload)

Run "appmctl artifacts <action> --help" for action-specific help.
EOF
}

command_artifacts() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _artifacts_list "$@" ;;
    get) _artifacts_get "$@" ;;
    upload) _artifacts_upload "$@" ;;
    delete) _artifacts_delete "$@" ;;
    -h|--help|"") artifacts_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown artifacts action: $action (use list, get, upload, delete)" ;;
  esac
}

_artifacts_list() {
  local app="${APP:-}"
  local build_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl artifacts list [--app APP] --build-id ID\n'
        return 0
        ;;
      *) die "unknown artifacts list option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id/artifacts"

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
  local fmt='{"type":"table","fields":["id","name","created_at"],"headers":["id","name","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_artifacts_get() {
  local app="${APP:-}"
  local build_id=""
  local artifact_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl artifacts get [--app APP] --build-id ID <artifact-id>\n'
        return 0
        ;;
      -*) die "unknown artifacts get option: $1" ;;
      *)
        [ -z "$artifact_id" ] || die "unexpected argument: $1"
        artifact_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  [ -n "$artifact_id" ] || die "artifact ID is required"
  validate_slug "build ID" "$build_id"
  validate_slug "artifact ID" "$artifact_id"

  local path="apps/$APP/builds/$build_id/artifacts/$artifact_id/file"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  check_output_destination
  api_get "$path" || return 1
  emit_response
}

_artifacts_upload() {
  local app="${APP:-}"
  local build_id=""
  local name=""
  local metadata=""
  local file_path=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl artifacts upload [--app APP] --build-id ID --name NAME [--metadata JSON] --file FILE\n'
        return 0
        ;;
      *) die "unknown artifacts upload option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  [ -n "$name" ] || die "--name is required"
  [ -n "$file_path" ] || die "--file is required"
  [ -f "$file_path" ] || die "file does not exist: $file_path"
  validate_slug "build ID" "$build_id"

  local path="apps/$APP/builds/$build_id/artifacts"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'name: %s\n' "$name"
    printf 'file: %s\n' "$file_path"
    [ -n "$metadata" ] && printf 'metadata: %s\n' "$metadata"
    return 0
  fi

  require_curl
  require_jq
  ensure_tmpdir
  local url
  url=$(build_url "$path") || return 1

  reset_request
  REQUEST_BODY_KIND="multipart"
  REQUEST_FORMS+=("name=$name")
  [ -n "$metadata" ] && REQUEST_FORMS+=("metadata=$metadata")
  REQUEST_FILES+=("file=$file_path")

  perform_request POST "$url" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_artifacts_delete() {
  local app="${APP:-}"
  local build_id=""
  local artifact_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --build-id) require_value "$1" "$2"; build_id="$2"; shift 2 ;;
      --build-id=*) build_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl artifacts delete [--app APP] --build-id ID <artifact-id>\n'
        return 0
        ;;
      -*) die "unknown artifacts delete option: $1" ;;
      *)
        [ -z "$artifact_id" ] || die "unexpected argument: $1"
        artifact_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$build_id" ] || die "--build-id is required"
  [ -n "$artifact_id" ] || die "artifact ID is required"
  validate_slug "build ID" "$build_id"
  validate_slug "artifact ID" "$artifact_id"

  local path="apps/$APP/builds/$build_id/artifacts/$artifact_id"

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
  printf 'Artifact %s deleted\n' "$artifact_id"
}
