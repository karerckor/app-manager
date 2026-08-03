#!/usr/bin/env bash

# "assets" subcommands: list, show, download, upload, delete.
#
# Assets are app icon and splash screen images. Each app has at most one icon
# and one splash screen. Uploading replaces the existing asset.

assets_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] assets <action> [options]

Actions:
  list                List available asset download URLs
  show                Preview an asset (inline)
  download            Download an asset or a named variant
  upload              Upload or replace an icon or splash screen
  delete              Delete an asset

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --type TYPE         Asset type: icon or splashscreen (required for show, download, upload, delete)
  --file FILE         File to upload (upload)
  --variant VARIANT   Variant name (download; default "original")

Run "appmctl assets <action> --help" for action-specific help.
EOF
}

validate_asset_type() {
  local value="$1"
  case "$value" in
    icon|splashscreen) ;;
    *) die "--type must be \"icon\" or \"splashscreen\"" ;;
  esac
}

command_assets() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _assets_list "$@" ;;
    show) _assets_show "$@" ;;
    download) _assets_download "$@" ;;
    upload) _assets_upload "$@" ;;
    delete) _assets_delete "$@" ;;
    -h|--help|"") assets_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown assets action: $action (use list, show, download, upload, delete)" ;;
  esac
}

_assets_list() {
  local app="${APP:-}"

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl assets list [--app APP]\n'
        return 0
        ;;
      *) die "unknown assets list option: $1" ;;
    esac
  done

  APP="$app"
  require_app

  local path="apps/$APP/assets"

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
  local fmt='{"type":"table","fields":["asset","url"],"headers":["asset","url"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_assets_show() {
  local app="${APP:-}"
  local type=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --type) require_value "$1" "$2"; type="$2"; shift 2 ;;
      --type=*) type=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl assets show [--app APP] --type icon|splashscreen [-o FILE]\n'
        return 0
        ;;
      *) die "unknown assets show option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$type" ] || die "--type is required"
  validate_asset_type "$type"

  local path="apps/$APP/assets/$type"

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

_assets_download() {
  local app="${APP:-}"
  local type=""
  local variant="original"

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --type) require_value "$1" "$2"; type="$2"; shift 2 ;;
      --type=*) type=${1#*=}; shift ;;
      --variant) require_value "$1" "$2"; variant="$2"; shift 2 ;;
      --variant=*) variant=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl assets download [--app APP] --type icon|splashscreen [--variant VARIANT] [-o FILE]\n'
        printf '\nVariants:\n  original  Original file (default)\n  ios-icon-40  android-icon-mdpi  ... (see "appmctl assets list")\n'
        return 0
        ;;
      *) die "unknown assets download option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$type" ] || die "--type is required"
  validate_asset_type "$type"
  [ -n "$variant" ] || die "--variant must not be empty"

  local path="apps/$APP/assets/$type/files/$variant"

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

_assets_upload() {
  local app="${APP:-}"
  local type=""
  local file_path=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --type) require_value "$1" "$2"; type="$2"; shift 2 ;;
      --type=*) type=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl assets upload [--app APP] --type icon|splashscreen --file FILE\n'
        return 0
        ;;
      *) die "unknown assets upload option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$type" ] || die "--type is required"
  validate_asset_type "$type"
  [ -n "$file_path" ] || die "--file is required"
  [ -f "$file_path" ] || die "file does not exist: $file_path"

  local path="apps/$APP/assets/$type"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'file: %s\n' "$file_path"
    return 0
  fi

  require_curl
  require_jq
  ensure_tmpdir
  local url
  url=$(build_url "$path") || return 1

  reset_request
  REQUEST_BODY_KIND="multipart"
  REQUEST_FILES+=("file=$file_path")

  perform_request POST "$url" || return 1
  local fmt='{"type":"resource","fields":{"slug":"slug","name":"name","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_assets_delete() {
  local app="${APP:-}"
  local type=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --type) require_value "$1" "$2"; type="$2"; shift 2 ;;
      --type=*) type=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl assets delete [--app APP] --type icon|splashscreen\n'
        return 0
        ;;
      *) die "unknown assets delete option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$type" ] || die "--type is required"
  validate_asset_type "$type"

  local path="apps/$APP/assets/$type"

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
  printf 'Asset %s deleted\n' "$type"
}
