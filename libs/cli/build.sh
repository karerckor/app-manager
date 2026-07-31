#!/usr/bin/env bash

# Build a single distributable appmctl from the modular source files.
# Output goes to stdout. Usage: bash libs/cli/build.sh > libs/cli/bin/appmctl

set -euo pipefail

CLI_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB_DIR="$CLI_DIR/lib"

# Header
cat <<'EOF'
#!/usr/bin/env bash

# App Manager CLI. Keep this script compatible with Bash 3.2 (the version
# shipped with macOS) so it can run in CI without another language runtime.

set -o pipefail

APPMCTL_VERSION="0.2.0"
APPMCTL_NAME="appmctl"
EOF

# Library modules (strip shebang lines)
for module in core.sh config.sh http.sh format.sh; do
  printf '\n'
  grep -v '^#!/usr/bin/env bash' "$LIB_DIR/$module"
done

# Parsing module — but replace dispatch_command to inline commands
printf '\n'
grep -v '^#!/usr/bin/env bash' "$LIB_DIR/parsing.sh" | \
  sed '/dispatch_command()/,/^}/c\
dispatch_command() {\
  case "$COMMAND_NAME" in\
    request) command_request "${REMAINING_ARGS[@]}" ;;\
    stream) command_stream "${REMAINING_ARGS[@]}" ;;\
    apps) command_apps "${REMAINING_ARGS[@]}" ;;\
    builds) command_builds "${REMAINING_ARGS[@]}" ;;\
    logs) command_logs "${REMAINING_ARGS[@]}" ;;\
    artifacts) command_artifacts "${REMAINING_ARGS[@]}" ;;\
    credentials) command_credentials "${REMAINING_ARGS[@]}" ;;\
    integrations) command_integrations "${REMAINING_ARGS[@]}" ;;\
    pipelines) command_pipelines "${REMAINING_ARGS[@]}" ;;\
    webhooks) command_webhooks "${REMAINING_ARGS[@]}" ;;\
    '"'"''"'"') usage; return 1 ;;\
  esac\
}'

# Command modules (strip shebang lines)
for cmd in request stream builds logs artifacts apps credentials integrations pipelines webhooks; do
  printf '\n'
  grep -v '^#!/usr/bin/env bash' "$LIB_DIR/commands/$cmd.sh"
done

# Entry point
printf '\nmain "$@"\n'
