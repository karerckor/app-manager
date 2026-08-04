#!/usr/bin/env bash
# Brings the Compose stack up in admin mode, asserts it actually works, tears it
# down. Run locally after changing the image or the stack; CI runs this file.
#
#   APP_MANAGER_IMAGE=app-manager-platform:dev ./deploy/docker/smoke-test.sh
set -euo pipefail

cd "$(dirname "$0")"

IMAGE="${APP_MANAGER_IMAGE:-ghcr.io/karerckor/app-manager-platform:latest}"
PORT="${SMOKE_PORT:-8099}"
PROJECT="am-smoke-$$"
ENV_FILE=".env"
ADMIN_EMAIL="smoke@example.com"
ADMIN_PASSWORD="Sm0keTest!Pass"

compose() {
    APP_MANAGER_IMAGE="$IMAGE" docker compose -p "$PROJECT" "$@"
}

cleanup() {
    local status=$?
    if [ $status -ne 0 ]; then
        echo "--- smoke test failed, dumping logs ---" >&2
        compose logs --no-color --tail=100 >&2 || true
    fi
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    exit $status
}
trap cleanup EXIT

if [ -e "$ENV_FILE" ]; then
    echo "refusing to overwrite an existing $PWD/$ENV_FILE" >&2
    exit 1
fi

cat > "$ENV_FILE" <<EOF
APP_KEY=$(docker run --rm "$IMAGE" app-manager generate-key | tail -1)
APP_URL=http://localhost:${PORT}
APP_PORT=${PORT}
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=app_manager
DB_USERNAME=app_manager
DB_PASSWORD=smoke-secret
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CLIENT=phpredis
CACHE_STORE=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
ALLOW_PUBLIC_REGISTRATION=false
APP_MANAGER_BOOTSTRAP=admin
ADMIN_NAME=Smoke Tester
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
ADMIN_TEAM_NAME=Smoke Team
EOF

echo "==> bringing up $IMAGE"
compose up -d --wait --wait-timeout 300

echo "==> init exited cleanly"
init_exit="$(docker inspect -f '{{.State.ExitCode}}' "$(compose ps -aq init)")"
[ "$init_exit" = "0" ] || { echo "init exited with $init_exit" >&2; exit 1; }

echo "==> /up responds"
for _ in $(seq 1 60); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/up" || true)"
    [ "$code" = "200" ] && break
    sleep 2
done
[ "$code" = "200" ] || { echo "/up returned $code" >&2; exit 1; }

echo "==> admin mode provisioned the owner and closed the installer"
users="$(compose exec -T postgres psql -U app_manager -d app_manager -tAc \
    "select count(*) from users where email = '${ADMIN_EMAIL}'")"
[ "$users" = "1" ] || { echo "expected 1 admin user, found '$users'" >&2; exit 1; }

login_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/login")"
[ "$login_code" = "200" ] || { echo "/login returned $login_code" >&2; exit 1; }

install_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/install")"
[ "$install_code" = "404" ] || { echo "/install returned $install_code, expected 404" >&2; exit 1; }

register_code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/register")"
[ "$register_code" = "404" ] || { echo "/register returned $register_code, expected 404" >&2; exit 1; }

echo "==> the queue worker drains a job"
# tinker --execute runs its argument through eval(), so a closure defined
# there has no real source file to serialize (Laravel's queued-closure
# serializer needs one). Writing the dispatch to a real file and requiring it
# gives the closure an actual file to reflect on.
compose exec -T app sh -c "cat > /tmp/smoke-dispatch.php <<'PHP'
<?php
dispatch(function () {
    \Illuminate\Support\Facades\Cache::put('smoke', 'ok', 300);
});
PHP
php artisan tinker --no-interaction --execute=\"require '/tmp/smoke-dispatch.php';\""
for _ in $(seq 1 30); do
    value="$(compose exec -T app php artisan tinker --no-interaction --execute="echo \Illuminate\Support\Facades\Cache::get('smoke', 'pending');" | tr -d '\r\n')"
    [ "$value" = "ok" ] && break
    sleep 2
done
[ "$value" = "ok" ] || { echo "queue worker did not process the job (cache value: '$value')" >&2; exit 1; }

echo "==> smoke test passed"
