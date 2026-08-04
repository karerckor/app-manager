#!/bin/sh
# Runs on every container start, before the role command. Sourced in a subshell
# by the base image's entrypoint, so a non-zero exit aborts the container start
# and exported variables do NOT reach the final process.
set -eu

APP_DIR="${APP_BASE_DIR:-/var/www/html}"
KEY_FILE="${APP_DIR}/storage/app/.app-key"
MODE="${APP_MANAGER_BOOTSTRAP:-installer}"

# `app-manager generate-key` must work on a container that has no key yet.
case "${DOCKER_CMD:-}" in
    *generate-key*) exit 0 ;;
esac

mkdir -p \
    "${APP_DIR}/storage/app/private" \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/bootstrap/cache"

if [ -z "${APP_KEY:-}" ]; then
    if [ "$MODE" != "demo" ]; then
        cat >&2 <<'MSG'
APP_KEY is not set.

Every credential in App Manager is encrypted with it: generate one, store it
with the rest of your secrets, and pass it to every container in the stack.

    docker run --rm ghcr.io/karerckor/app-manager-platform:latest app-manager generate-key

Only APP_MANAGER_BOOTSTRAP=demo generates a throwaway key by itself.
MSG
        exit 1
    fi

    if [ ! -f "$KEY_FILE" ]; then
        app-manager generate-key > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi

    # Real environment variables win over .env, so this only fills the gap.
    printf 'APP_KEY=%s\n' "$(cat "$KEY_FILE")" > "${APP_DIR}/.env"
fi

# Cached per container, because bootstrap/cache is not shared between them.
# route:cache is impossible here: routes/web.php registers a closure route.
php artisan config:cache
php artisan view:cache
