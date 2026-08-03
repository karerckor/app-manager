#!/usr/bin/env bash

# "files" subcommands: list, get, upload, update, delete.

files_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] files <action> [options]

Actions:
  list                List files attached to an app
  show                Show a single file's metadata
  get                 Download a file
  upload              Attach a file to an app
  update              Replace a file's metadata
  delete              Remove a file

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --name NAME         File name (required for show, get, update, delete)
  --file FILE         File to upload (upload)
  --metadata JSON     JSON object with metadata (upload, update; "null" clears)
  --per-page N        Page size for list

Run "appmctl files <action> --help" for action-specific help.
EOF
}

# Percent-encode a single path segment. ASCII only; use "appmctl request" for
# names outside the ASCII range.
url_encode_segment() {
  local string="$1"
  local out=""
  local length=${#string}
  local i=0
  local char

  while [ "$i" -lt "$length" ]; do
    char=${string:$i:1}
    case "$char" in
      [A-Za-z0-9._~-]) out="$out$char" ;;
      *) out="$out$(printf '%%%02X' "'$char")" ;;
    esac
    i=$((i + 1))
  done

  printf '%s' "$out"
}

validate_file_name() {
  local value="$1"
  case "$value" in
    ''|.|..) die "--name must be a file name" ;;
    */*|*\\*) die "--name must not contain path separators" ;;
    *$'\n'*|*$'\r'*) die "--name must not contain line breaks" ;;
  esac
}

command_files() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _files_list "$@" ;;
    show) _files_show "$@" ;;
    get) _files_get "$@" ;;
    upload) _files_upload "$@" ;;
    update) _files_update "$@" ;;
    delete) _files_delete "$@" ;;
    -h|--help|"") files_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown files action: $action (use list, show, get, upload, update, delete)" ;;
  esac
}

_files_list() {
  local app="${APP:-}"
  local per_page=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --per-page) require_value "$1" "$2"; per_page="$2"; shift 2 ;;
      --per-page=*) per_page=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files list [--app APP] [--per-page N]\n'
        return 0
        ;;
      *) die "unknown files list option: $1" ;;
    esac
  done

  APP="$app"
  require_app

  local path="apps/$APP/files"
  if [ -n "$per_page" ]; then
    case "$per_page" in
      ''|*[!0-9]*) die "--per-page must be a positive integer" ;;
    esac
    path="$path?per_page=$per_page"
  fi

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
  local fmt='{"type":"table","fields":["name","mime_type","size","created_at"],"headers":["name","type","size","created_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_show() {
  local app="${APP:-}"
  local name=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files show [--app APP] --name NAME\n'
        return 0
        ;;
      -*) die "unknown files show option: $1" ;;
      *)
        [ -z "$name" ] || die "unexpected argument: $1"
        name="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"

  local path="apps/$APP/files/$(url_encode_segment "$name")"

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
  # "metadata" is an object; render it as compact JSON so the aligned output
  # stays on one line, and blank when the file carries no metadata.
  local fmt='{"type":"resource","fields":{"name":"name","extension":"extension","mime_type":"mime_type","size":"size","metadata":"metadata // empty | tojson","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_get() {
  local app="${APP:-}"
  local name=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files get [--app APP] --name NAME [-o FILE]\n'
        return 0
        ;;
      -*) die "unknown files get option: $1" ;;
      *)
        [ -z "$name" ] || die "unexpected argument: $1"
        name="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"

  local path="apps/$APP/files/$(url_encode_segment "$name")/content"

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

_files_upload() {
  local app="${APP:-}"
  local file_path=""
  local metadata=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files upload [--app APP] --file FILE [--metadata JSON]\n'
        return 0
        ;;
      *) die "unknown files upload option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$file_path" ] || die "--file is required"
  [ -f "$file_path" ] || die "file does not exist: $file_path"

  local path="apps/$APP/files"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
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
  [ -n "$metadata" ] && REQUEST_FORMS+=("metadata=$metadata")
  REQUEST_FILES+=("file=$file_path")

  perform_request POST "$url" || return 1
  local fmt='{"type":"resource","fields":{"name":"name","mime_type":"mime_type","size":"size","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_update() {
  local app="${APP:-}"
  local name=""
  local metadata=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files update [--app APP] --name NAME --metadata JSON\n'
        return 0
        ;;
      *) die "unknown files update option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"
  [ -n "$metadata" ] || die "--metadata is required"

  local path="apps/$APP/files/$(url_encode_segment "$name")"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: PATCH\n'
    printf 'url: %s\n' "$url"
    printf 'metadata: %s\n' "$metadata"
    return 0
  fi

  require_jq
  ensure_tmpdir
  local body_file
  body_file=$(new_temp_file files-update) || return 1
  printf '%s' "$metadata" | "$JQ_BIN" '{metadata: .}' >"$body_file" \
    || die "--metadata must be valid JSON"

  api_patch "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"name":"name","mime_type":"mime_type","size":"size","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_files_delete() {
  local app="${APP:-}"
  local name=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl files delete [--app APP] --name NAME\n'
        return 0
        ;;
      -*) die "unknown files delete option: $1" ;;
      *)
        [ -z "$name" ] || die "unexpected argument: $1"
        name="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$name" ] || die "--name is required"
  validate_file_name "$name"

  local path="apps/$APP/files/$(url_encode_segment "$name")"

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
  printf 'File %s deleted\n' "$name"
}
