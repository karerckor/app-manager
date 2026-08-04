#!/bin/sh
# Runs on every container start, before the role command. Sourced in a subshell
# by the base image's entrypoint, so a non-zero exit aborts the container start
# without affecting the parent shell. Exports made here don't reach the role
# command the parent execs afterwards, but they do reach any command *this
# script* still runs below (config:cache, view:cache) — see the APP_KEY
# handling for why that's enough.
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

    # A Compose `env_file: .env` with a blank `APP_KEY=` line sets APP_KEY to
    # an empty string, not nothing — Laravel's dotenv repository sees that as
    # already defined and refuses to load a replacement from a file, so an
    # `.env` fallback here is silently ignored. Exporting it instead reaches
    # `config:cache` below directly (it's a child of this script, not of the
    # parent that execs the role command), and it's that cached config every
    # later process reads app.key from, so the export never needing to reach
    # the role command itself is fine.
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# Cached per container, because bootstrap/cache is not shared between them.
# route:cache is impossible here: routes/web.php registers a closure route.
php artisan config:cache
php artisan view:cache
