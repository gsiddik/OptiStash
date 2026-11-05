#!/bin/sh
# OptiStash container entrypoint.
#   entrypoint.sh bootstrap   one-shot job: configuration check, wait for the database, migrations, permission catalog and system roles,
#                             optional first platform administrator and optional demo data
#   entrypoint.sh <anything>  runtime role (php-fpm): waits (bounded) for the bootstrap to have finished, then runs
# Only the bootstrap job changes the database schema. Waits are bounded and end with a clear message; an invalid configuration exits instead of looping.
set -eu

APP_USER=www-data
WRITABLE_DIRS="storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

fail() { echo "ERROR: $*" >&2; exit 1; }

if [ "$(id -u)" = "0" ]; then
  mkdir -p $WRITABLE_DIRS
  find storage bootstrap/cache ! -user "$APP_USER" -exec chown "$APP_USER:$APP_USER" {} + 2>/dev/null || true
  exec su-exec "$APP_USER" "$0" "$@"
fi

mkdir -p $WRITABLE_DIRS

DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-90}"
BOOTSTRAP_WAIT_SECONDS="${BOOTSTRAP_WAIT_SECONDS:-300}"

# wait_until <seconds> <command...>: run the command every 2 s until it succeeds or the time is up.
wait_until() {
  limit="$1"; shift
  waited=0
  until "$@" >/dev/null 2>&1; do
    waited=$((waited + 2))
    [ "$waited" -lt "$limit" ] || return 1
    sleep 2
  done
}

if [ "${1:-}" = "bootstrap" ]; then
  echo "[bootstrap] checking configuration"
  [ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty (generate one with: php artisan key:generate --show)"
  case "${OPTISTASH_IDENTITY_MODE:-local}" in
    local) ;;
    optinexus) [ -n "${OPTINEXUS_BASE_URL:-}" ] || fail "OPTISTASH_IDENTITY_MODE=optinexus needs OPTINEXUS_BASE_URL" ;;
    *) fail "OPTISTASH_IDENTITY_MODE must be local or optinexus" ;;
  esac
  case "${OPTISTASH_DEPLOYMENT_MODE:-standalone}" in
    standalone|saas) ;;
    *) fail "OPTISTASH_DEPLOYMENT_MODE must be standalone or saas" ;;
  esac

  echo "[bootstrap] waiting for the database (at most ${DB_WAIT_SECONDS}s)"
  wait_until "$DB_WAIT_SECONDS" php artisan db:show --no-interaction \
    || fail "the database did not become reachable within ${DB_WAIT_SECONDS}s (check DB_HOST/DB_PASSWORD)"

  echo "[bootstrap] migrating"
  php artisan migrate --force --no-interaction
  echo "[bootstrap] seeding (production-safe seeder) and syncing permissions and system roles"
  php artisan db:seed --force --no-interaction
  php artisan optistash:sync-access --no-interaction

  # The first Superadmin, only when both values are given. Idempotent: an existing account is promoted, its password is never reset.
  if [ -n "${OPTISTASH_PLATFORM_ADMIN_EMAIL:-}" ] && [ -n "${OPTISTASH_PLATFORM_ADMIN_PASSWORD:-}" ]; then
    echo "[bootstrap] platform administrator"
    php artisan optistash:create-platform-admin "$OPTISTASH_PLATFORM_ADMIN_EMAIL" --no-interaction
  fi

  case "${OPTISTASH_SEED:-catalog}" in
    catalog) ;;
    # DemoSeeder refuses anything but APP_ENV=local/testing and needs OPTISTASH_DEMO_PASSWORD; it never resets an existing password.
    demo) echo "[bootstrap] demo data (tenant demo)"; php artisan db:seed --class=DemoSeeder --force --no-interaction ;;
    *) fail "OPTISTASH_SEED must be catalog or demo" ;;
  esac
  echo "[bootstrap] complete"
  exit 0
fi

# Runtime roles never migrate. They start when the schema is current, or stop with a message instead of looping forever.
pending() { php artisan migrate:status --pending --no-interaction 2>&1 | grep -qi "no pending migrations"; }
wait_until "$BOOTSTRAP_WAIT_SECONDS" pending \
  || fail "the database schema is not current after ${BOOTSTRAP_WAIT_SECONDS}s: run the bootstrap job (docker compose -f docker-compose.container.yml run --rm bootstrap) and start again"

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
