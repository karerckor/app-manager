#!/usr/bin/env bash

# "credentials" subcommands: list, get, create, update, delete, download, upload,
# verify, types.

credentials_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] credentials <action> [options]

Actions:
  list                List credentials for an app
  get                 Show credential details
  create              Create a credential
  update              Update a credential
  delete              Delete a credential
  download            Download a credential file
  upload              Upload a credential file
  verify              Report a verification verdict for an external vault reference
  types               List available credential types

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --credential-id ID  Credential ID (required for get, update, delete, download, upload)
  --type TYPE         Credential type slug (create)
  --profile PROFILE   Build profile (create)
  --data-file FILE    Credential data as JSON from FILE or stdin (-) (create, update)
  --data JSON         Credential data as inline JSON (deprecated; may leak via process arguments)
  --file FILE         Credential file to upload (create, upload)
  --status STATUS     Reported verdict: verified or failed (verify)
  --error TEXT        Why resolution failed; required with --status failed (verify)
  --expires-at DATE   Expiry discovered in the vault; only with --status verified (verify)
  --metadata JSON     JSON object of string values describing the resolved secret (verify)

Run "appmctl credentials <action> --help" for action-specific help.
EOF
}

command_credentials() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _credentials_list "$@" ;;
    get) _credentials_get "$@" ;;
    create) _credentials_create "$@" ;;
    update) _credentials_update "$@" ;;
    delete) _credentials_delete "$@" ;;
    download) _credentials_download "$@" ;;
    upload) _credentials_upload "$@" ;;
    verify) _credentials_verify "$@" ;;
    types) _credentials_types "$@" ;;
    -h|--help|"") credentials_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown credentials action: $action (use list, get, create, update, delete, download, upload, verify, types)" ;;
  esac
}

_credentials_list() {
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
        printf 'Usage: appmctl credentials list [--app APP]\n'
        return 0
        ;;
      *) die "unknown credentials list option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  local path="apps/$APP/credentials"

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
  local fmt='{"type":"table","fields":["id","type","name","expires_at"],"headers":["id","type","name","expires_at"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_credentials_get() {
  local app="${APP:-}"
  local cred_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials get [--app APP] <credential-id>\n'
        return 0
        ;;
      -*) die "unknown credentials get option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"

  local path="apps/$APP/credentials/$cred_id"

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
  local fmt='{"type":"resource","fields":{"id":"id","type":"type","name":"name","profiles":"profiles // [] | map(.type) | join(\", \")","expires_at":"expires_at","file":"file.name","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_credentials_create() {
  local app="${APP:-}"
  local cred_type=""
  local profile=""
  local data=""
  local data_file=""
  local file_path=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --type) require_value "$1" "$2"; cred_type="$2"; shift 2 ;;
      --type=*) cred_type=${1#*=}; shift ;;
      --profile) require_value "$1" "$2"; profile="$2"; shift 2 ;;
      --profile=*) profile=${1#*=}; shift ;;
      --data) require_value "$1" "$2"; data="$2"; shift 2 ;;
      --data=*) data=${1#*=}; shift ;;
      --data-file) require_value "$1" "$2"; data_file="$2"; shift 2 ;;
      --data-file=*) data_file=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials create [--app APP] --type TYPE --profile PROFILE [--data-file FILE|-] [--file FILE]\n'
        return 0
        ;;
      *) die "unknown credentials create option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_type" ] || die "--type is required"
  [ -n "$profile" ] || die "--profile is required"
  [ -z "$data" ] || [ -z "$data_file" ] || die "use only one of --data or --data-file"

  ensure_tmpdir
  body_file=$(new_temp_file credential-body) || die "could not create temp file"
  chmod 600 "$body_file" 2>/dev/null || true
  local jq_args=(-n --arg t "$cred_type" --arg p "$profile")
  local jq_expr='{credential_type: {slug: $t}, build_profile: $p'
  if [ -n "$data" ] || [ -n "$data_file" ]; then
    local secret_file
    if [ -n "$data_file" ] && [ "$data_file" != "-" ]; then
      [ -f "$data_file" ] || die "credential data file does not exist: $data_file"
      secret_file="$data_file"
    else
      secret_file=$(new_temp_file credential-data) || die "could not create credential data file"
      chmod 600 "$secret_file" 2>/dev/null || true
      if [ "$data_file" = "-" ]; then
        cat >"$secret_file"
      else
        printf '%s' "$data" >"$secret_file"
      fi
    fi
    jq_args+=(--slurpfile d "$secret_file")
    jq_expr="$jq_expr, data: \$d[0]"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file"

  local path="apps/$APP/credentials"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: POST\n'
    printf 'url: %s\n' "$url"
    printf 'body: credential JSON (%s bytes; redacted)\n' "$(wc -c <"$body_file" | tr -d '[:space:]')"
    rm -f -- "$body_file"
    return 0
  fi

  api_post "$path" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  local fmt='{"type":"resource","fields":{"id":"id","type":"credential_type.slug","profile":"build_profile","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_credentials_update() {
  local app="${APP:-}"
  local cred_id=""
  local data=""
  local data_file=""
  local body_file
  local has_fields=0

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --data) require_value "$1" "$2"; data="$2"; has_fields=1; shift 2 ;;
      --data=*) data=${1#*=}; has_fields=1; shift ;;
      --data-file) require_value "$1" "$2"; data_file="$2"; has_fields=1; shift 2 ;;
      --data-file=*) data_file=${1#*=}; has_fields=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials update [--app APP] <credential-id> --data-file FILE|-\n'
        return 0
        ;;
      -*) die "unknown credentials update option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"
  [ "$has_fields" -eq 1 ] || die "at least one field to update is required"
  [ -z "$data" ] || [ -z "$data_file" ] || die "use only one of --data or --data-file"

  ensure_tmpdir
  body_file=$(new_temp_file credential-body) || die "could not create temp file"
  chmod 600 "$body_file" 2>/dev/null || true
  local secret_file
  if [ -n "$data_file" ] && [ "$data_file" != "-" ]; then
    [ -f "$data_file" ] || die "credential data file does not exist: $data_file"
    secret_file="$data_file"
  else
    secret_file=$(new_temp_file credential-data) || die "could not create credential data file"
    chmod 600 "$secret_file" 2>/dev/null || true
    if [ "$data_file" = "-" ]; then
      cat >"$secret_file"
    else
      printf '%s' "$data" >"$secret_file"
    fi
  fi
  "$JQ_BIN" -n --slurpfile d "$secret_file" '{data: $d[0]}' >"$body_file"

  local path="apps/$APP/credentials/$cred_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "$path") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: PATCH\n'
    printf 'url: %s\n' "$url"
    printf 'body: credential JSON (%s bytes; redacted)\n' "$(wc -c <"$body_file" | tr -d '[:space:]')"
    rm -f -- "$body_file"
    return 0
  fi

  api_patch "$path" "$body_file" || { rm -f -- "$body_file"; return 1; }
  rm -f -- "$body_file"
  printf 'Credential %s updated\n' "$cred_id"
}

_credentials_delete() {
  local app="${APP:-}"
  local cred_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials delete [--app APP] <credential-id>\n'
        return 0
        ;;
      -*) die "unknown credentials delete option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"

  local path="apps/$APP/credentials/$cred_id"

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
  printf 'Credential %s deleted\n' "$cred_id"
}

_credentials_download() {
  local app="${APP:-}"
  local cred_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials download [--app APP] <credential-id> [-o FILE]\n'
        return 0
        ;;
      -*) die "unknown credentials download option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"

  local path="apps/$APP/credentials/$cred_id/file"

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

_credentials_upload() {
  local app="${APP:-}"
  local cred_id=""
  local file_path=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --file) require_value "$1" "$2"; file_path="$2"; shift 2 ;;
      --file=*) file_path=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials upload [--app APP] <credential-id> --file FILE\n'
        return 0
        ;;
      -*) die "unknown credentials upload option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"
  [ -n "$file_path" ] || die "--file is required"
  [ -f "$file_path" ] || die "file does not exist: $file_path"

  local path="apps/$APP/credentials/$cred_id/file"

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
  ensure_tmpdir
  local url
  url=$(build_url "$path") || return 1

  reset_request
  REQUEST_BODY_KIND="multipart"
  REQUEST_FILES+=("file=$file_path")

  perform_request POST "$url" || return 1
  printf 'Credential file uploaded to %s\n' "$cred_id"
}

# App Manager never reaches into an external vault itself, so the verdict has to
# come from whoever can: the build. This action only transmits that verdict.
_credentials_verify() {
  local app="${APP:-}"
  local cred_id=""
  local status=""
  local error_text=""
  local expires_at=""
  local metadata=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --status) require_value "$1" "$2"; status="$2"; shift 2 ;;
      --status=*) status=${1#*=}; shift ;;
      --error) require_value "$1" "$2"; error_text="$2"; shift 2 ;;
      --error=*) error_text=${1#*=}; shift ;;
      --expires-at) require_value "$1" "$2"; expires_at="$2"; shift 2 ;;
      --expires-at=*) expires_at=${1#*=}; shift ;;
      --metadata) require_value "$1" "$2"; metadata="$2"; shift 2 ;;
      --metadata=*) metadata=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials verify [--app APP] <credential-id> --status verified|failed\n'
        printf '                                  [--error TEXT] [--expires-at DATE] [--metadata JSON]\n'
        return 0
        ;;
      -*) die "unknown credentials verify option: $1" ;;
      *)
        [ -z "$cred_id" ] || die "unexpected argument: $1"
        cred_id="$1"; shift ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$cred_id" ] || die "credential ID is required"

  # Mirror the API's rules rather than discovering them as a 422: a build that
  # passed contradictory flags should learn so before it spends a request.
  case "$status" in
    verified|failed) ;;
    "") die "--status is required (verified or failed)" ;;
    *) die "--status must be verified or failed, not $status" ;;
  esac

  if [ "$status" = "failed" ]; then
    [ -n "$error_text" ] || die "--error is required when --status is failed"
    [ -z "$expires_at" ] || die "--expires-at cannot be combined with --status failed"
  else
    [ -z "$error_text" ] || die "--error is only accepted when --status is failed"
  fi

  local metadata_json=""
  if [ -n "$metadata" ]; then
    metadata_json=$(
      printf '%s' "$metadata" |
        "$JQ_BIN" -c 'if type == "object" then . else error("not an object") end' 2>/dev/null
    ) || die "--metadata must be a JSON object of string values"
  fi

  ensure_tmpdir
  body_file=$(new_temp_file verification-body) || die "could not create temp file"

  local jq_args=(--arg st "$status")
  local jq_filter='{status: $st'

  if [ -n "$error_text" ]; then
    jq_args+=(--arg err "$error_text")
    jq_filter="$jq_filter, error: \$err"
  fi

  if [ -n "$expires_at" ]; then
    jq_args+=(--arg exp "$expires_at")
    jq_filter="$jq_filter, expires_at: \$exp"
  fi

  if [ -n "$metadata_json" ]; then
    jq_args+=(--argjson meta "$metadata_json")
    jq_filter="$jq_filter, metadata: \$meta"
  fi

  jq_filter="$jq_filter}"
  "$JQ_BIN" -nc "${jq_args[@]}" "$jq_filter" >"$body_file"

  local path="apps/$APP/credentials/$cred_id/verification"

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
  local fmt='{"type":"resource","fields":{"provider":"provider","resource":"resource","status":"verification.status","verified_at":"verification.verified_at","error":"verification.error","metadata":"metadata // empty | tojson","metadata_version":"metadata_version","metadata_source":"metadata_source"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_credentials_types() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials types\n'
        return 0
        ;;
      *) die "unknown credentials types option: $1" ;;
    esac
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    local url
    require_config
    url=$(build_url "credential-types") || return 1
    printf 'dry-run: no request sent\n'
    printf 'method: GET\n'
    printf 'url: %s\n' "$url"
    return 0
  fi

  api_get "credential-types" || return 1
  local fmt='{"type":"table","fields":["slug","name","platform"],"headers":["slug","name","platform"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}
