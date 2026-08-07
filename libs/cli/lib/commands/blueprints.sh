#!/usr/bin/env bash

# "blueprints" subcommands: blueprint CRUD/evaluation and nested hook CRUD.

blueprints_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] blueprints <action> [options]
  appmctl [global options] blueprints hooks <action> [options]

Blueprint actions:
  list                List team blueprints
  get                 Show a blueprint and its hooks
  create              Create a draft or complete blueprint
  update              Update blueprint fields
  delete              Delete a blueprint
  evaluate            Run hooks against attached applications

Hook actions:
  list                List hooks for a blueprint
  get                 Show one hook
  create              Create and evaluate a hook
  update              Update and evaluate a hook
  delete              Delete a hook without changing existing tags

Run "appmctl blueprints <action> --help" or
"appmctl blueprints hooks <action> --help" for action-specific help.
EOF
}

command_blueprints() {
  local action="${1:-}"
  shift 2>/dev/null || true

  case "$action" in
    list) _blueprints_list "$@" ;;
    get) _blueprints_get "$@" ;;
    create) _blueprints_save create "$@" ;;
    update) _blueprints_save update "$@" ;;
    delete) _blueprints_delete "$@" ;;
    evaluate) _blueprints_evaluate "$@" ;;
    hooks) _blueprints_hooks "$@" ;;
    -h|--help|"") blueprints_usage; [ -z "$action" ] && return 1; return 0 ;;
    *) die "unknown blueprints action: $action (use list, get, create, update, delete, evaluate, hooks)" ;;
  esac
}

_blueprints_dry_run() {
  local method="$1"
  local path="$2"
  local body_file="${3:-}"
  local url

  require_config
  url=$(build_url "$path") || return 1
  printf 'dry-run: no request sent\n'
  printf 'method: %s\n' "$method"
  printf 'url: %s\n' "$url"
  if [ -n "$body_file" ]; then
    printf 'body: %s\n' "$(cat "$body_file")"
  fi
}

_blueprints_list() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints list\n'; return 0 ;;
      *) die "unknown blueprints list option: $1" ;;
    esac
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    _blueprints_dry_run GET blueprints
    return
  fi

  api_get blueprints || return 1
  local fmt='{"type":"table","fields":["id","name","platforms | join(\", \")","required_files | length","applications_count","hooks_count"],"headers":["id","name","platforms","files","apps","hooks"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_get() {
  local blueprint_id=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints get <blueprint-id>\n'; return 0 ;;
      -*) die "unknown blueprints get option: $1" ;;
      *) [ -z "$blueprint_id" ] || die "unexpected argument: $1"; blueprint_id="$1"; shift ;;
    esac
  done

  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  local path="blueprints/$blueprint_id"

  if [ "$DRY_RUN" -eq 1 ]; then
    _blueprints_dry_run GET "$path"
    return
  fi

  api_get "$path" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","description":"description","platforms":"platforms | join(\", \")","required_files":"required_files | join(\", \")","applications":"applications_count","hooks":"hooks_count","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_save() {
  local mode="$1"
  shift
  local blueprint_id=""
  local name=""
  local description=""
  local schema=""
  local schema_file=""
  local required_files=""
  local platforms=""
  local name_set=0
  local description_set=0
  local schema_set=0
  local required_files_set=0
  local platforms_set=0
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; name_set=1; shift 2 ;;
      --name=*) name=${1#*=}; name_set=1; shift ;;
      --description) require_value "$1" "$2"; description="$2"; description_set=1; shift 2 ;;
      --description=*) description=${1#*=}; description_set=1; shift ;;
      --clear-description) description=""; description_set=1; shift ;;
      --json-schema) require_value "$1" "$2"; schema="$2"; schema_set=1; shift 2 ;;
      --json-schema=*) schema=${1#*=}; schema_set=1; shift ;;
      --json-schema-file) require_value "$1" "$2"; schema_file="$2"; schema_set=1; shift 2 ;;
      --json-schema-file=*) schema_file=${1#*=}; schema_set=1; shift ;;
      --required-files) require_value "$1" "$2"; required_files="$2"; required_files_set=1; shift 2 ;;
      --required-files=*) required_files=${1#*=}; required_files_set=1; shift ;;
      --platforms) require_value "$1" "$2"; platforms="$2"; platforms_set=1; shift 2 ;;
      --platforms=*) platforms=${1#*=}; platforms_set=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        if [ "$mode" = "create" ]; then
          printf 'Usage: appmctl blueprints create --name NAME [--description DESC] [--json-schema JSON|--json-schema-file FILE] [--required-files JSON] [--platforms JSON]\n'
        else
          printf 'Usage: appmctl blueprints update <blueprint-id> [--name NAME] [--description DESC|--clear-description] [--json-schema JSON|--json-schema-file FILE] [--required-files JSON] [--platforms JSON]\n'
        fi
        return 0
        ;;
      -*) die "unknown blueprints $mode option: $1" ;;
      *)
        [ "$mode" = "update" ] || die "unexpected argument: $1"
        [ -z "$blueprint_id" ] || die "unexpected argument: $1"
        blueprint_id="$1"
        shift
        ;;
    esac
  done

  if [ "$mode" = "create" ]; then
    [ "$name_set" -eq 1 ] && [ -n "$name" ] || die "--name is required"
  else
    [ -n "$blueprint_id" ] || die "blueprint ID is required"
    validate_slug "blueprint ID" "$blueprint_id"
    [ $((name_set + description_set + schema_set + required_files_set + platforms_set)) -gt 0 ] \
      || die "at least one field to update is required"
  fi

  [ -z "$schema" ] || [ -z "$schema_file" ] || die "use only one of --json-schema or --json-schema-file"
  if [ -n "$schema_file" ]; then
    [ -f "$schema_file" ] || die "JSON schema file does not exist: $schema_file"
    schema=$(cat "$schema_file") || die "could not read JSON schema file: $schema_file"
  fi

  require_jq
  if [ "$schema_set" -eq 1 ]; then
    printf '%s' "$schema" | "$JQ_BIN" -e 'type == "object" or . == null' >/dev/null 2>&1 \
      || die "--json-schema must be a JSON object or null"
  fi
  if [ "$required_files_set" -eq 1 ]; then
    printf '%s' "$required_files" | "$JQ_BIN" -e 'type == "array" or . == null' >/dev/null 2>&1 \
      || die "--required-files must be a JSON array or null"
  fi
  if [ "$platforms_set" -eq 1 ]; then
    printf '%s' "$platforms" | "$JQ_BIN" -e 'type == "array" or . == null' >/dev/null 2>&1 \
      || die "--platforms must be a JSON array or null"
  fi

  ensure_tmpdir
  body_file=$(new_temp_file blueprint-body) || die "could not create a request body"
  local jq_args=(-n)
  local jq_expr='{}'
  if [ "$name_set" -eq 1 ]; then jq_args+=(--arg value "$name"); jq_expr="$jq_expr | .name = \$value"; fi
  if [ "$description_set" -eq 1 ]; then
    if [ -n "$description" ]; then jq_args+=(--arg description "$description"); jq_expr="$jq_expr | .description = \$description"
    else jq_expr="$jq_expr | .description = null"; fi
  fi
  if [ "$schema_set" -eq 1 ]; then jq_args+=(--argjson schema "$schema"); jq_expr="$jq_expr | .json_schema = \$schema"; fi
  if [ "$required_files_set" -eq 1 ]; then jq_args+=(--argjson files "$required_files"); jq_expr="$jq_expr | .required_files = \$files"; fi
  if [ "$platforms_set" -eq 1 ]; then jq_args+=(--argjson platforms "$platforms"); jq_expr="$jq_expr | .platforms = \$platforms"; fi
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" || die "could not build blueprint request"

  local path="blueprints"
  local method="POST"
  if [ "$mode" = "update" ]; then path="$path/$blueprint_id"; method="PATCH"; fi

  if [ "$DRY_RUN" -eq 1 ]; then
    _blueprints_dry_run "$method" "$path" "$body_file"
    return
  fi

  if [ "$mode" = "create" ]; then api_post "$path" "$body_file" || return 1
  else api_patch "$path" "$body_file" || return 1; fi
  local fmt='{"type":"resource","fields":{"id":"id","name":"name","description":"description","platforms":"platforms | join(\", \")","required_files":"required_files | join(\", \")","applications":"applications_count","hooks":"hooks_count"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_delete() {
  local blueprint_id=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints delete <blueprint-id>\n'; return 0 ;;
      -*) die "unknown blueprints delete option: $1" ;;
      *) [ -z "$blueprint_id" ] || die "unexpected argument: $1"; blueprint_id="$1"; shift ;;
    esac
  done
  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  local path="blueprints/$blueprint_id"
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run DELETE "$path"; return; fi
  api_delete "$path" || return 1
  printf 'Blueprint %s deleted\n' "$blueprint_id"
}

_blueprints_evaluate() {
  local blueprint_id=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints evaluate <blueprint-id>\n'; return 0 ;;
      -*) die "unknown blueprints evaluate option: $1" ;;
      *) [ -z "$blueprint_id" ] || die "unexpected argument: $1"; blueprint_id="$1"; shift ;;
    esac
  done
  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  local path="blueprints/$blueprint_id/evaluate"
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run POST "$path"; return; fi
  api_post "$path" "" || return 1
  local fmt='{"type":"resource","fields":{"evaluated_applications":"evaluated_applications"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_hooks() {
  local action="${1:-}"
  shift 2>/dev/null || true
  case "$action" in
    list) _blueprints_hooks_list "$@" ;;
    get) _blueprints_hooks_get "$@" ;;
    create) _blueprints_hook_save create "$@" ;;
    update) _blueprints_hook_save update "$@" ;;
    delete) _blueprints_hooks_delete "$@" ;;
    -h|--help|"")
      cat <<'EOF'
Usage:
  appmctl blueprints hooks list <blueprint-id>
  appmctl blueprints hooks get <blueprint-id> <hook-id>
  appmctl blueprints hooks create <blueprint-id> --name NAME --condition CONDITION --actions JSON
  appmctl blueprints hooks update <blueprint-id> <hook-id> [fields]
  appmctl blueprints hooks delete <blueprint-id> <hook-id>
EOF
      [ -z "$action" ] && return 1
      return 0
      ;;
    *) die "unknown blueprints hooks action: $action (use list, get, create, update, delete)" ;;
  esac
}

_blueprints_hooks_list() {
  local blueprint_id=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints hooks list <blueprint-id>\n'; return 0 ;;
      -*) die "unknown blueprints hooks list option: $1" ;;
      *) [ -z "$blueprint_id" ] || die "unexpected argument: $1"; blueprint_id="$1"; shift ;;
    esac
  done
  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  local path="blueprints/$blueprint_id/hooks"
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run GET "$path"; return; fi
  api_get "$path" || return 1
  local fmt='{"type":"table","fields":["id","name","condition","actions | length","enabled"],"headers":["id","name","condition","actions","enabled"]}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_hooks_get() {
  local blueprint_id=""
  local hook_id=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints hooks get <blueprint-id> <hook-id>\n'; return 0 ;;
      -*) die "unknown blueprints hooks get option: $1" ;;
      *)
        if [ -z "$blueprint_id" ]; then blueprint_id="$1"
        elif [ -z "$hook_id" ]; then hook_id="$1"
        else die "unexpected argument: $1"; fi
        shift
        ;;
    esac
  done
  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  [ -n "$hook_id" ] || die "hook ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  validate_slug "hook ID" "$hook_id"
  local path="blueprints/$blueprint_id/hooks/$hook_id"
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run GET "$path"; return; fi
  api_get "$path" || return 1
  local fmt='{"type":"resource","fields":{"id":"id","blueprint_id":"blueprint_id","name":"name","condition":"condition","actions":"actions | length","enabled":"enabled","created_at":"created_at","updated_at":"updated_at"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_hook_save() {
  local mode="$1"
  shift
  local blueprint_id=""
  local hook_id=""
  local name=""
  local condition=""
  local condition_config=""
  local actions=""
  local actions_file=""
  local enabled=""
  local name_set=0
  local condition_set=0
  local condition_config_set=0
  local actions_set=0
  local enabled_set=0
  local body_file

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) require_value "$1" "$2"; name="$2"; name_set=1; shift 2 ;;
      --name=*) name=${1#*=}; name_set=1; shift ;;
      --condition) require_value "$1" "$2"; condition="$2"; condition_set=1; shift 2 ;;
      --condition=*) condition=${1#*=}; condition_set=1; shift ;;
      --condition-config) require_value "$1" "$2"; condition_config="$2"; condition_config_set=1; shift 2 ;;
      --condition-config=*) condition_config=${1#*=}; condition_config_set=1; shift ;;
      --actions) require_value "$1" "$2"; actions="$2"; actions_set=1; shift 2 ;;
      --actions=*) actions=${1#*=}; actions_set=1; shift ;;
      --actions-file) require_value "$1" "$2"; actions_file="$2"; actions_set=1; shift 2 ;;
      --actions-file=*) actions_file=${1#*=}; actions_set=1; shift ;;
      --enabled) require_value "$1" "$2"; enabled="$2"; enabled_set=1; shift 2 ;;
      --enabled=*) enabled=${1#*=}; enabled_set=1; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output) require_value "$1" "$2"; OUTPUT_FILE="$2"; shift 2 ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help)
        if [ "$mode" = "create" ]; then
          printf 'Usage: appmctl blueprints hooks create <blueprint-id> --name NAME --condition CONDITION --actions JSON|--actions-file FILE [--condition-config JSON] [--enabled true|false]\n'
        else
          printf 'Usage: appmctl blueprints hooks update <blueprint-id> <hook-id> [--name NAME] [--condition CONDITION] [--condition-config JSON] [--actions JSON|--actions-file FILE] [--enabled true|false]\n'
        fi
        return 0
        ;;
      -*) die "unknown blueprints hooks $mode option: $1" ;;
      *)
        if [ -z "$blueprint_id" ]; then blueprint_id="$1"
        elif [ "$mode" = "update" ] && [ -z "$hook_id" ]; then hook_id="$1"
        else die "unexpected argument: $1"; fi
        shift
        ;;
    esac
  done

  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  if [ "$mode" = "create" ]; then
    [ "$name_set" -eq 1 ] && [ -n "$name" ] || die "--name is required"
    [ "$condition_set" -eq 1 ] && [ -n "$condition" ] || die "--condition is required"
    [ "$actions_set" -eq 1 ] || die "--actions or --actions-file is required"
  else
    [ -n "$hook_id" ] || die "hook ID is required"
    validate_slug "hook ID" "$hook_id"
    [ $((name_set + condition_set + condition_config_set + actions_set + enabled_set)) -gt 0 ] \
      || die "at least one field to update is required"
  fi

  [ -z "$actions" ] || [ -z "$actions_file" ] || die "use only one of --actions or --actions-file"
  if [ -n "$actions_file" ]; then
    [ -f "$actions_file" ] || die "actions file does not exist: $actions_file"
    actions=$(cat "$actions_file") || die "could not read actions file: $actions_file"
  fi

  require_jq
  if [ "$condition_config_set" -eq 1 ]; then
    printf '%s' "$condition_config" | "$JQ_BIN" -e 'type == "object" or . == null' >/dev/null 2>&1 \
      || die "--condition-config must be a JSON object or null"
  fi
  if [ "$actions_set" -eq 1 ]; then
    printf '%s' "$actions" | "$JQ_BIN" -e 'type == "array" and length > 0' >/dev/null 2>&1 \
      || die "--actions must be a non-empty JSON array"
  fi
  if [ "$enabled_set" -eq 1 ]; then
    case "$enabled" in true|false) ;; *) die "--enabled must be true or false" ;; esac
  fi

  ensure_tmpdir
  body_file=$(new_temp_file blueprint-hook-body) || die "could not create a request body"
  local jq_args=(-n)
  local jq_expr='{}'
  if [ "$name_set" -eq 1 ]; then jq_args+=(--arg name "$name"); jq_expr="$jq_expr | .name = \$name"; fi
  if [ "$condition_set" -eq 1 ]; then jq_args+=(--arg condition "$condition"); jq_expr="$jq_expr | .condition = \$condition"; fi
  if [ "$condition_config_set" -eq 1 ]; then jq_args+=(--argjson config "$condition_config"); jq_expr="$jq_expr | .condition_config = \$config"; fi
  if [ "$actions_set" -eq 1 ]; then jq_args+=(--argjson actions "$actions"); jq_expr="$jq_expr | .actions = \$actions"; fi
  if [ "$enabled_set" -eq 1 ]; then jq_args+=(--argjson enabled "$enabled"); jq_expr="$jq_expr | .enabled = \$enabled"; fi
  "$JQ_BIN" "${jq_args[@]}" "$jq_expr" >"$body_file" || die "could not build hook request"

  local path="blueprints/$blueprint_id/hooks"
  local method="POST"
  if [ "$mode" = "update" ]; then path="$path/$hook_id"; method="PATCH"; fi
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run "$method" "$path" "$body_file"; return; fi
  if [ "$mode" = "create" ]; then api_post "$path" "$body_file" || return 1
  else api_patch "$path" "$body_file" || return 1; fi
  local fmt='{"type":"resource","fields":{"id":"id","blueprint_id":"blueprint_id","name":"name","condition":"condition","actions":"actions | length","enabled":"enabled"}}'
  emit_formatted_response "$LAST_RESPONSE_FILE" "$fmt"
}

_blueprints_hooks_delete() {
  local blueprint_id=""
  local hook_id=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      -h|--help) printf 'Usage: appmctl blueprints hooks delete <blueprint-id> <hook-id>\n'; return 0 ;;
      -*) die "unknown blueprints hooks delete option: $1" ;;
      *)
        if [ -z "$blueprint_id" ]; then blueprint_id="$1"
        elif [ -z "$hook_id" ]; then hook_id="$1"
        else die "unexpected argument: $1"; fi
        shift
        ;;
    esac
  done
  [ -n "$blueprint_id" ] || die "blueprint ID is required"
  [ -n "$hook_id" ] || die "hook ID is required"
  validate_slug "blueprint ID" "$blueprint_id"
  validate_slug "hook ID" "$hook_id"
  local path="blueprints/$blueprint_id/hooks/$hook_id"
  if [ "$DRY_RUN" -eq 1 ]; then _blueprints_dry_run DELETE "$path"; return; fi
  api_delete "$path" || return 1
  printf 'Blueprint hook %s deleted\n' "$hook_id"
}
