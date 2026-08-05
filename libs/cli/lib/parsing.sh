#!/usr/bin/env bash

# Global option parsing and subcommand dispatch.

COMMAND_NAME=""
REMAINING_ARGS=()

usage() {
  cat <<'EOF'
App Manager CLI

Usage:
  appmctl [global options] <resource> <action> [options]
  appmctl [global options] request METHOD PATH [request options]
  appmctl [global options] stream [stream options] -- COMMAND [ARG ...]

Resources:
  apps            Manage mobile applications
  builds          Manage builds
  logs            Read and write build logs
  artifacts       Manage build artifacts
  files           Manage files attached to an app
  assets          Manage app icons and splash screens
  identifiers     Manage per-platform app identifiers
  credentials     Manage app credentials
  integrations    Manage integrations
  eas             Manage the Expo/EAS integration
  pipelines       Manage CI/CD pipelines
  webhooks        Manage webhook endpoints
  sentry          Manage routed Sentry DSNs
  telemetry       Inspect application issues and traces

Required environment:
  APP_MANAGER_API_KEY       API key used as a Bearer token
  APP_MANAGER_TEAM          Team slug used in every API URL

Optional environment:
  APP_MANAGER_INSTANCE_URL  Server URL; defaults to http://localhost:8000
  APP_MANAGER_APP           Default app slug for app-scoped commands
  APP_MANAGER_POLLING_INTERVAL  Polling interval in seconds (default: 10)

Global options:
  --instance-url URL        Override APP_MANAGER_INSTANCE_URL
  --team TEAM               Override APP_MANAGER_TEAM
  --app APP                 Override APP_MANAGER_APP
  --dry-run                 Print the request plan without calling the API
  --json                    Pretty-print and validate a JSON response with jq
  -o, --output FILE         Save the response instead of writing it to stdout
  --force                   Allow --output to replace an existing file
  -h, --help                Show help
  --version                 Show the CLI version

Run "appmctl <resource> --help" for resource-specific help.
Run "appmctl request --help" or "appmctl stream --help" for legacy command help.
EOF
}

parse_global_options() {
  COMMAND_NAME=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --instance-url)
        require_value "$1" "$2"
        INSTANCE_URL="$2"
        shift 2
        ;;
      --instance-url=*) INSTANCE_URL=${1#*=}; shift ;;
      --team)
        require_value "$1" "$2"
        TEAM="$2"
        shift 2
        ;;
      --team=*) TEAM=${1#*=}; shift ;;
      --app)
        require_value "$1" "$2"
        APP="$2"
        shift 2
        ;;
      --app=*) APP=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output)
        require_value "$1" "$2"
        OUTPUT_FILE="$2"
        shift 2
        ;;
      --force) FORCE_OUTPUT=1; shift ;;
      --version)
        printf '%s %s\n' "$APPMCTL_NAME" "$APPMCTL_VERSION"
        exit 0
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      request|stream|apps|builds|logs|artifacts|files|assets|identifiers|credentials|integrations|eas|pipelines|webhooks|sentry|telemetry)
        COMMAND_NAME="$1"
        shift
        REMAINING_ARGS=("$@")
        return 0
        ;;
      --) die "a command name must appear before --" ;;
      -*) die "unknown global option: $1" ;;
      *) die "unknown command: $1" ;;
    esac
  done
}

dispatch_command() {
  local cmd_dir="$APPMCTL_LIB_DIR/commands"

  case "$COMMAND_NAME" in
    request) source "$cmd_dir/request.sh"; command_request "${REMAINING_ARGS[@]}" ;;
    stream) source "$cmd_dir/stream.sh"; command_stream "${REMAINING_ARGS[@]}" ;;
    apps) source "$cmd_dir/apps.sh"; command_apps "${REMAINING_ARGS[@]}" ;;
    builds) source "$cmd_dir/builds.sh"; command_builds "${REMAINING_ARGS[@]}" ;;
    logs) source "$cmd_dir/logs.sh"; command_logs "${REMAINING_ARGS[@]}" ;;
    artifacts) source "$cmd_dir/artifacts.sh"; command_artifacts "${REMAINING_ARGS[@]}" ;;
    files) source "$cmd_dir/files.sh"; command_files "${REMAINING_ARGS[@]}" ;;
    assets) source "$cmd_dir/assets.sh"; command_assets "${REMAINING_ARGS[@]}" ;;
    identifiers) source "$cmd_dir/identifiers.sh"; command_identifiers "${REMAINING_ARGS[@]}" ;;
    credentials) source "$cmd_dir/credentials.sh"; command_credentials "${REMAINING_ARGS[@]}" ;;
    integrations) source "$cmd_dir/integrations.sh"; command_integrations "${REMAINING_ARGS[@]}" ;;
    eas) source "$cmd_dir/eas.sh"; command_eas "${REMAINING_ARGS[@]}" ;;
    pipelines) source "$cmd_dir/pipelines.sh"; command_pipelines "${REMAINING_ARGS[@]}" ;;
    webhooks) source "$cmd_dir/webhooks.sh"; command_webhooks "${REMAINING_ARGS[@]}" ;;
    sentry) source "$cmd_dir/sentry.sh"; command_sentry "${REMAINING_ARGS[@]}" ;;
    telemetry) source "$cmd_dir/telemetry.sh"; command_telemetry "${REMAINING_ARGS[@]}" ;;
    '') usage; return 1 ;;
  esac
}

main() {
  if [ "$#" -eq 0 ]; then
    usage
    return 1
  fi

  parse_global_options "$@"
  dispatch_command
}
