#!/usr/bin/env bash

# "identifiers" subcommands: list, show, create, update, delete.
#
# App identifiers are the per-platform bundle IDs, package names, and web domains
# an app ships under. Uniqueness, the Android-only version_code rule, and the web
# domain format are enforced by the API, not here, so the CLI cannot drift from it.

identifiers_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] identifiers <action> [options]

Actions:
  list                List identifiers for an app
  show                Show a single identifier
  create              Add an identifier
  update              Update an identifier
  delete              Remove an identifier

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --platform PLATFORM ios, android, or web (required for create)
  --identifier ID     Bundle ID, package name, or domain (required for create)
  --name NAME         Display name
  --version VERSION   Marketing version
  --version-code N    Android version code (android only)
  --build-number N    iOS build number (iOS only)
  --default           Make this the default for its platform
  --no-default        Clear the default flag when updating
  --per-page N        Page size for list

Run "appmctl identifiers <action> --help" for action-specific help.
EOF
}

validate_platform() {
  case "$1" in
    ios|android|web) ;;
    *) die "--platform must be \"ios\", \"android\", or \"web\"" ;;
  esac
}

command_identifiers() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _identifiers_list "$@" ;;
    show) _identifiers_show "$@" ;;
    create) _identifiers_create "$@" ;;
    update) _identifiers_update "$@" ;;
    delete) _identifiers_delete "$@" ;;
    -h|--help|"") identifiers_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown identifiers action: $action (use list, show, create, update, delete)" ;;
  esac
}

_identifiers_list() {
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
        printf 'Usage: appmctl identifiers list [--app APP] [--per-page N]\n'
        return 0
        ;;
      *) die "unknown identifiers list option: $1" ;;
    esac
  done

  APP="$app"
  require_app

  local path="apps/$APP/identifiers"
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
  local fmt='{"type":"table","fields":["id","platform","identifier","name","version"],"headers":["id","platform","identifier","name","version"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_identifiers_show() {
  local app="${APP:-}"
  local identifier_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl identifiers show [--app APP] <identifier-id>\n'
        return 0
        ;;
      -*) die "unknown identifiers show option: $1" ;;
      *)
        [ -z "$identifier_id" ] || die "unexpected argument: $1"
        identifier_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$identifier_id" ] || die "identifier ID is required"

  local path="apps/$APP/identifiers/$identifier_id"

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
  emit_formatted_response "$LAST_RESPONSE_FILE" "$(_identifiers_resource_format)"
}

_identifiers_create() {
  local app="${APP:-}"
  local platform=""
  local identifier=""
  local name=""
  local version=""
  local version_code=""
  local build_number=""
  local default_state=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --platform) require_value "$1" "$2"; platform="$2"; shift 2 ;;
      --platform=*) platform=${1#*=}; shift ;;
      --identifier) require_value "$1" "$2"; identifier="$2"; shift 2 ;;
      --identifier=*) identifier=${1#*=}; shift ;;
      --name) require_value "$1" "$2"; name="$2"; shift 2 ;;
      --name=*) name=${1#*=}; shift ;;
      --version) require_value "$1" "$2"; version="$2"; shift 2 ;;
      --version=*) version=${1#*=}; shift ;;
      --version-code) require_value "$1" "$2"; version_code="$2"; shift 2 ;;
      --version-code=*) version_code=${1#*=}; shift ;;
      --build-number) require_value "$1" "$2"; build_number="$2"; shift 2 ;;
      --build-number=*) build_number=${1#*=}; shift ;;
      --default) default_state=true; shift ;;
      --no-default) default_state=false; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl identifiers create [--app APP] --platform ios|android|web --identifier ID [--name NAME] [--version VERSION] [--version-code N] [--build-number N] [--default]\n'
        return 0
        ;;
      *) die "unknown identifiers create option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$platform" ] || die "--platform is required"
  validate_platform "$platform"
  [ -n "$identifier" ] || die "--identifier is required"
  _identifiers_validate_version_code "$version_code"

  require_jq
  ensure_tmpdir
  body_file=$(new_temp_file identifiers-create) || return 1
  _identifiers_build_body "$body_file" "$platform" "$identifier" "$name" "$version" "$version_code" "$build_number" "$default_state"

  local path="apps/$APP/identifiers"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: %s\n' "$(cat "$body_file")"
    return 0
  fi

  api_post "$path" "$body_file" || return 1
  emit_formatted_response "$LAST_RESPONSE_FILE" "$(_identifiers_resource_format)"
}

_identifiers_update() {
  local app="${APP:-}"
  local identifier_id=""
  local platform=""
  local identifier=""
  local name=""
  local version=""
  local version_code=""
  local build_number=""
  local default_state=""
  local has_fields=0
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --platform) require_value "$1" "$2"; platform="$2"; has_fields=1; shift 2 ;;
      --platform=*) platform=${1#*=}; has_fields=1; shift ;;
      --identifier) require_value "$1" "$2"; identifier="$2"; has_fields=1; shift 2 ;;
      --identifier=*) identifier=${1#*=}; has_fields=1; shift ;;
      --name) require_value "$1" "$2"; name="$2"; has_fields=1; shift 2 ;;
      --name=*) name=${1#*=}; has_fields=1; shift ;;
      --version) require_value "$1" "$2"; version="$2"; has_fields=1; shift 2 ;;
      --version=*) version=${1#*=}; has_fields=1; shift ;;
      --version-code) require_value "$1" "$2"; version_code="$2"; has_fields=1; shift 2 ;;
      --version-code=*) version_code=${1#*=}; has_fields=1; shift ;;
      --build-number) require_value "$1" "$2"; build_number="$2"; has_fields=1; shift 2 ;;
      --build-number=*) build_number=${1#*=}; has_fields=1; shift ;;
      --default) default_state=true; has_fields=1; shift ;;
      --no-default) default_state=false; has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl identifiers update [--app APP] <identifier-id> [--platform PLATFORM] [--identifier ID] [--name NAME] [--version VERSION] [--version-code N] [--build-number N] [--default|--no-default]\n'
        return 0
        ;;
      -*) die "unknown identifiers update option: $1" ;;
      *)
        [ -z "$identifier_id" ] || die "unexpected argument: $1"
        identifier_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$identifier_id" ] || die "identifier ID is required"
  [ "$has_fields" -eq 1 ] || die "at least one field to update is required"
  [ -z "$platform" ] || validate_platform "$platform"
  _identifiers_validate_version_code "$version_code"

  require_jq
  ensure_tmpdir
  body_file=$(new_temp_file identifiers-update) || return 1
  _identifiers_build_body "$body_file" "$platform" "$identifier" "$name" "$version" "$version_code" "$build_number" "$default_state"

  local path="apps/$APP/identifiers/$identifier_id"

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
  emit_formatted_response "$LAST_RESPONSE_FILE" "$(_identifiers_resource_format)"
}

_identifiers_delete() {
  local app="${APP:-}"
  local identifier_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl identifiers delete [--app APP] <identifier-id>\n'
        return 0
        ;;
      -*) die "unknown identifiers delete option: $1" ;;
      *)
        [ -z "$identifier_id" ] || die "unexpected argument: $1"
        identifier_id="$1"
        shift
        ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$identifier_id" ] || die "identifier ID is required"

  local path="apps/$APP/identifiers/$identifier_id"

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
  printf 'Identifier %s deleted\n' "$identifier_id"
}

# version_code is an integer on the API. Reject non-numeric input locally so the
# body stays well-typed; the android-only restriction is left to the API.
_identifiers_validate_version_code() {
  [ -n "$1" ] || return 0
  case "$1" in
    ''|*[!0-9]*) die "--version-code must be a positive integer" ;;
  esac
  [ "$1" -ge 1 ] || die "--version-code must be a positive integer"
}

_identifiers_build_body() {
  local body_file="$1"
  local platform="$2"
  local identifier="$3"
  local name="$4"
  local version="$5"
  local version_code="$6"
  local build_number="$7"
  local default_state="$8"

  local jq_args=(-n)
  local jq_expr='{'
  local first=1

  if [ -n "$platform" ]; then
    jq_args+=(--arg platform "$platform")
    jq_expr="$jq_expr platform: \$platform"
    first=0
  fi
  if [ -n "$identifier" ]; then
    jq_args+=(--arg identifier "$identifier")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr identifier: \$identifier"
    first=0
  fi
  if [ -n "$name" ]; then
    jq_args+=(--arg name "$name")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr name: \$name"
    first=0
  fi
  if [ -n "$version" ]; then
    jq_args+=(--arg version "$version")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr version: \$version"
    first=0
  fi
  if [ -n "$version_code" ]; then
    jq_args+=(--argjson version_code "$version_code")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr version_code: \$version_code"
    first=0
  fi
  if [ -n "$build_number" ]; then
    jq_args+=(--arg build_number "$build_number")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr build_number: \$build_number"
    first=0
  fi
  if [ -n "$default_state" ]; then
    jq_args+=(--argjson is_default "$default_state")
    [ "$first" -eq 0 ] && jq_expr="$jq_expr,"
    jq_expr="$jq_expr is_default: \$is_default"
    first=0
  fi

  jq_expr="$jq_expr }"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" || die "could not build the request body"
}

_identifiers_resource_format() {
  printf '%s' '{"type":"resource","fields":{"id":"id","platform":"platform","identifier":"identifier","name":"name","version":"version","version_code":"version_code","build_number":"build_number","is_default":"is_default","created_at":"created_at","updated_at":"updated_at"}}'
}
