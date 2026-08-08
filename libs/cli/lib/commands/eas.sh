#!/usr/bin/env bash

# "eas" subcommands for the Expo/EAS integration.
#
# The API splits across two scopes: the Expo account, which hangs off an
# integration, and the project link, which hangs off an app. Actions that exist in
# both scopes pick one from the flag you pass -- --integration-id targets the
# account, --app targets the project link -- and refuse to guess when given both.
#
# The GraphQL and REST proxies are deliberately absent. Their payloads are
# pass-through, so "appmctl request" serves them better than a wrapper would.

eas_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] eas <action> [options]

Account actions (Expo account on an integration):
  connect             Connect an Expo account with an access token
  projects            List projects visible to the connected account
  import              Import an Expo project as an App Manager app

Project actions (link between an app and an Expo project):
  status              Show the linked project and its sync state
  link                Link an app to an Expo project
  auto-sync           Enable or disable automatic sync
  identifiers         List identifiers with their Expo sync status
  sync-identifiers    Push local identifiers to Expo
  provision           Provision Expo credentials for an identifier
  attach-credential   Push one stored credential to Expo

Either scope:
  sync                Re-sync (--app for a project, --integration-id for an account)
  disconnect          Unlink a project (--app) or remove the account (--integration-id)

Options:
  --app APP           App slug (default: APP_MANAGER_APP)
  --integration-id ID Expo integration ID
  --token TOKEN       Expo access token (connect)
  --account NAME      Expo account name (connect)
  --project-id ID     Expo project ID (import, link)
  --default-ios-identifier ID
                      Default iOS identifier for an import
  --default-android-identifier ID
                      Default Android identifier for an import
  --mode MODE         create or existing (link)
  --enabled BOOL      true or false (auto-sync)
  --identifier-id ID  App identifier ID (provision, attach-credential)
  --credential-id ID  Credential ID (attach-credential)
  --credential-ids L  Comma-separated credential IDs (provision)

Run "appmctl eas <action> --help" for action-specific help.
EOF
}

command_eas() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    connect) _eas_connect "$@" ;;
    status) _eas_status "$@" ;;
    sync) _eas_sync "$@" ;;
    disconnect) _eas_disconnect "$@" ;;
    projects) _eas_projects "$@" ;;
    import) _eas_import "$@" ;;
    link) _eas_link "$@" ;;
    auto-sync) _eas_auto_sync "$@" ;;
    identifiers) _eas_identifiers "$@" ;;
    sync-identifiers) _eas_sync_identifiers "$@" ;;
    provision) _eas_provision "$@" ;;
    attach-credential) _eas_attach_credential "$@" ;;
    -h|--help|"") eas_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown eas action: $action (run \"appmctl eas --help\" for the list)" ;;
  esac
}

_eas_dry_run_plan() {
  local method="$1"
  local path="$2"
  local url
  require_config
  url=$(build_url "$path") || return 1
  printf 'dry-run: no request sent\n'
  printf 'method: %s\n' "$method"
  printf 'url: %s\n' "$url"
}

_eas_project_format() {
  printf '%s' '{"type":"resource","fields":{"project":"project.full_name","slug":"project.slug","account":"project.account.name","expo_project_id":"project.external_id","exists_on_expo":"project.exists_on_expo","automatic_sync":"project.automatic_sync.enabled","auto_sync_status":"project.automatic_sync.status","last_synced_at":"project.last_synced_at"}}'
}

_eas_connect() {
  local token=""
  local account=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --token) require_value "$1" "$2"; token="$2"; shift 2 ;;
      --token=*) token=${1#*=}; shift ;;
      --account) require_value "$1" "$2"; account="$2"; shift 2 ;;
      --account=*) account=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas connect --token TOKEN [--account NAME]\n'
        printf '\nThe token is read from the argument only for parity with the API; prefer\n'
        printf 'exporting it and using --token "$EXPO_TOKEN" so it stays out of shell history.\n'
        return 0
        ;;
      *) die "unknown eas connect option: $1" ;;
    esac
  done

  [ -n "$token" ] || die "--token is required"

  require_jq
  ensure_tmpdir
  body_file=$(new_temp_file eas-connect) || return 1
  local jq_args=(-n --arg token "$token")
  local jq_expr='{access_token: $token'
  if [ -n "$account" ]; then
    jq_args+=(--arg account "$account")
    jq_expr="$jq_expr, account_name: \$account"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" || die "could not build the request body"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "integrations/expo" || return 1
    printf 'body: expo credentials (%s bytes; redacted)\n' "$(wc -c <"$body_file" | tr -d '[:space:]')"
    return 0
  fi

  api_post "integrations/expo" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","provider":"provider","account":"name","status":"status","created_at":"created_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_status() {
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
        printf 'Usage: appmctl eas status [--app APP]\n'
        return 0
        ;;
      *) die "unknown eas status option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  local path="apps/$APP/expo"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan GET "$path"
    return 0
  fi

  api_get "$path" || return 1
  emit_formatted_response "$LAST_RESPONSE_FILE" "$(_eas_project_format)"
}

# Actions valid in both scopes resolve the target from whichever flag is present.
# An explicit --app plus an explicit --integration-id is an error, not a preference.
_eas_sync() {
  local app=""
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas sync (--app APP | --integration-id ID)\n'
        return 0
        ;;
      *) die "unknown eas sync option: $1" ;;
    esac
  done

  if [ -n "$app" ] && [ -n "$integration_id" ]; then
    die "pass either --app or --integration-id, not both"
  fi

  local path
  if [ -n "$integration_id" ]; then
    path="integrations/$integration_id/expo/sync"
  else
    APP="${app:-${APP:-}}"
    [ -n "$APP" ] || die "--app or --integration-id is required"
    require_app
    path="apps/$APP/expo/sync"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path"
    return 0
  fi

  api_post "$path" "" || return 1
  emit_response
}

_eas_disconnect() {
  local app=""
  local integration_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas disconnect (--app APP | --integration-id ID)\n'
        printf '\n--app unlinks the project and leaves the account connected.\n'
        printf '--integration-id removes the Expo account from the team.\n'
        return 0
        ;;
      *) die "unknown eas disconnect option: $1" ;;
    esac
  done

  if [ -n "$app" ] && [ -n "$integration_id" ]; then
    die "pass either --app or --integration-id, not both"
  fi

  local path
  local scope
  if [ -n "$integration_id" ]; then
    path="integrations/$integration_id/expo"
    scope="account"
  else
    APP="${app:-${APP:-}}"
    [ -n "$APP" ] || die "--app or --integration-id is required"
    require_app
    path="apps/$APP/expo"
    scope="project"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan DELETE "$path"
    return 0
  fi

  api_delete "$path" || return 1
  if [ "$scope" = "account" ]; then
    printf 'Expo account disconnected from integration %s\n' "$integration_id"
  else
    printf 'Expo project unlinked from %s\n' "$APP"
  fi
}

_eas_projects() {
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
        printf 'Usage: appmctl eas projects --integration-id ID\n'
        return 0
        ;;
      *) die "unknown eas projects option: $1" ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  local path="integrations/$integration_id/expo/projects"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan GET "$path"
    return 0
  fi

  api_get "$path" || return 1
  local fmt='{"type":"table","fields":["id","full_name","external_id","exists_on_expo","last_synced_at"],"headers":["id","project","expo_id","on_expo","last_synced"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_import() {
  local integration_id=""
  local project_id=""
  local default_ios_identifier=""
  local default_android_identifier=""
  local body_file=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --project-id) require_value "$1" "$2"; project_id="$2"; shift 2 ;;
      --project-id=*) project_id=${1#*=}; shift ;;
      --default-ios-identifier) require_value "$1" "$2"; default_ios_identifier="$2"; shift 2 ;;
      --default-ios-identifier=*) default_ios_identifier=${1#*=}; shift ;;
      --default-android-identifier) require_value "$1" "$2"; default_android_identifier="$2"; shift 2 ;;
      --default-android-identifier=*) default_android_identifier=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas import --integration-id ID --project-id ID [options]\n'
        printf '\nOptions:\n'
        printf '  --default-ios-identifier ID      Default imported iOS bundle identifier\n'
        printf '  --default-android-identifier ID  Default imported Android application ID\n'
        printf '\nA platform with one Expo identifier is selected automatically.\n'
        printf 'Use "appmctl eas projects" to find the project ID.\n'
        return 0
        ;;
      *) die "unknown eas import option: $1" ;;
    esac
  done

  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$project_id" ] || die "--project-id is required"
  local path="integrations/$integration_id/expo/projects/$project_id/import"

  if [ -n "$default_ios_identifier" ] || [ -n "$default_android_identifier" ]; then
    require_jq
    ensure_tmpdir
    body_file=$(new_temp_file eas-import) || return 1
    "$JQ_BIN" -n \
      --arg ios "$default_ios_identifier" \
      --arg android "$default_android_identifier" \
      '{default_identifiers: ({ios: $ios, android: $android} | with_entries(select(.value != "")))}' \
      >"$body_file" || die "could not build the request body"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path" || return 1
    if [ -n "$body_file" ]; then
      printf 'body: %s\n' "$(cat "$body_file")"
    fi
    return 0
  fi

  api_post "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"project":"full_name","expo_project_id":"external_id","app":"mobile_app.slug","exists_on_expo":"exists_on_expo"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_link() {
  local app="${APP:-}"
  local integration_id=""
  local mode=""
  local project_id=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --integration-id) require_value "$1" "$2"; integration_id="$2"; shift 2 ;;
      --integration-id=*) integration_id=${1#*=}; shift ;;
      --mode) require_value "$1" "$2"; mode="$2"; shift 2 ;;
      --mode=*) mode=${1#*=}; shift ;;
      --project-id) require_value "$1" "$2"; project_id="$2"; shift 2 ;;
      --project-id=*) project_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas link [--app APP] --integration-id ID --mode create|existing [--project-id ID]\n'
        printf '\nmode=create makes a new Expo project for the app.\n'
        printf 'mode=existing links to an Expo project that already exists and needs --project-id.\n'
        return 0
        ;;
      *) die "unknown eas link option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$integration_id" ] || die "--integration-id is required"
  [ -n "$mode" ] || die "--mode is required"
  case "$mode" in
    create|existing) ;;
    *) die "--mode must be \"create\" or \"existing\"" ;;
  esac
  if [ "$mode" = "existing" ] && [ -z "$project_id" ]; then
    die "--project-id is required when --mode is existing"
  fi

  require_jq
  ensure_tmpdir
  body_file=$(new_temp_file eas-link) || return 1
  local jq_args=(-n --arg integration "$integration_id" --arg mode "$mode")
  local jq_expr='{integration_id: $integration, mode: $mode'
  if [ -n "$project_id" ]; then
    jq_args+=(--arg project "$project_id")
    jq_expr="$jq_expr, expo_project_id: \$project"
  fi
  jq_expr="$jq_expr}"
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" || die "could not build the request body"

  local path="apps/$APP/expo"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path" || return 1
    printf 'body: %s\n' "$(cat "$body_file")"
    return 0
  fi

  api_post "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"project":"full_name","slug":"slug","expo_project_id":"external_id","exists_on_expo":"exists_on_expo"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_auto_sync() {
  local app="${APP:-}"
  local enabled=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --enabled) require_value "$1" "$2"; enabled="$2"; shift 2 ;;
      --enabled=*) enabled=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas auto-sync [--app APP] --enabled true|false\n'
        return 0
        ;;
      *) die "unknown eas auto-sync option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$enabled" ] || die "--enabled is required"
  case "$enabled" in
    true|false) ;;
    *) die "--enabled must be \"true\" or \"false\"" ;;
  esac

  require_jq
  ensure_tmpdir
  body_file=$(new_temp_file eas-auto-sync) || return 1
  "$JQ_BIN" -n --argjson enabled "$enabled" '{enabled: $enabled}' >"$body_file" \
    || die "could not build the request body"

  local path="apps/$APP/expo/automatic-sync"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan PATCH "$path" || return 1
    printf 'body: %s\n' "$(cat "$body_file")"
    return 0
  fi

  api_patch "$path" "$body_file" || return 1
  local fmt='{"type":"resource","fields":{"enabled":"automatic_sync.enabled","status":"automatic_sync.status","error":"automatic_sync.error","last_pushed_at":"automatic_sync.last_pushed_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_identifiers() {
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
        printf 'Usage: appmctl eas identifiers [--app APP]\n'
        printf '\nsync_status is one of synced, app_manager_only, or expo_only.\n'
        return 0
        ;;
      *) die "unknown eas identifiers option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  local path="apps/$APP/expo/identifiers"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan GET "$path"
    return 0
  fi

  api_get "$path" || return 1
  local fmt='{"type":"table","fields":["local_id","platform","identifier","name","sync_status"],"headers":["local_id","platform","identifier","name","sync_status"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_eas_sync_identifiers() {
  local app="${APP:-}"

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas sync-identifiers [--app APP]\n'
        return 0
        ;;
      *) die "unknown eas sync-identifiers option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  local path="apps/$APP/expo/identifiers/sync"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path"
    return 0
  fi

  api_post "$path" "" || return 1
  emit_response
}

_eas_provision() {
  local app="${APP:-}"
  local identifier_id=""
  local credential_ids=""
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --identifier-id) require_value "$1" "$2"; identifier_id="$2"; shift 2 ;;
      --identifier-id=*) identifier_id=${1#*=}; shift ;;
      --credential-ids) require_value "$1" "$2"; credential_ids="$2"; shift 2 ;;
      --credential-ids=*) credential_ids=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas provision [--app APP] --identifier-id ID [--credential-ids ID,ID]\n'
        printf '\nWithout --credential-ids the API provisions whatever the identifier is missing.\n'
        return 0
        ;;
      *) die "unknown eas provision option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$identifier_id" ] || die "--identifier-id is required"

  local path="apps/$APP/expo/identifiers/$identifier_id/provision"
  local body_arg=""

  if [ -n "$credential_ids" ]; then
    require_jq
    ensure_tmpdir
    body_file=$(new_temp_file eas-provision) || return 1
    "$JQ_BIN" -n --arg ids "$credential_ids" \
      '{credential_ids: ($ids | split(",") | map(select(length > 0)))}' >"$body_file" \
      || die "could not build the request body"
    body_arg="$body_file"
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path" || return 1
    [ -n "$body_arg" ] && printf 'body: %s\n' "$(cat "$body_arg")"
    return 0
  fi

  api_post "$path" "$body_arg" || return 1
  emit_response
}

_eas_attach_credential() {
  local app="${APP:-}"
  local identifier_id=""
  local credential_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --identifier-id) require_value "$1" "$2"; identifier_id="$2"; shift 2 ;;
      --identifier-id=*) identifier_id=${1#*=}; shift ;;
      --credential-id) require_value "$1" "$2"; credential_id="$2"; shift 2 ;;
      --credential-id=*) credential_id=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl eas attach-credential [--app APP] --identifier-id ID --credential-id ID\n'
        return 0
        ;;
      *) die "unknown eas attach-credential option: $1" ;;
    esac
  done

  APP="$app"
  require_app
  [ -n "$identifier_id" ] || die "--identifier-id is required"
  [ -n "$credential_id" ] || die "--credential-id is required"

  local path="apps/$APP/expo/identifiers/$identifier_id/credentials/$credential_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    _eas_dry_run_plan POST "$path"
    return 0
  fi

  api_post "$path" "" || return 1
  emit_response
}
