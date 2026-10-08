#!/usr/bin/env bash
###############################################################################
# Aqdi Backend — Railway startup
# SAFETY: the database is wiped + reseeded ONLY when ALLOW_DB_RESET=true and the
# environment is not production. Otherwise it runs forward migrations only, so a
# real database can never be destroyed by a deploy or a crash-restart.
# To rebuild the TEST data on deploy, set ALLOW_DB_RESET=true in Railway.
#
# Scheduler: `php artisan schedule:work` runs in the background (smart
# notifications every 15 min, daily DB backup, heartbeat for /api/v2/health).
# It is supervised by a small restart loop so a crash never takes the web
# server down — and the web server starts even if the scheduler fails.
###############################################################################
set -e

echo "[railway-start] clearing config cache"
php artisan config:clear || true
php artisan cache:clear || true

if [ "$APP_ENV" != "production" ] && [ "$ALLOW_DB_RESET" = "true" ]; then
  echo "[railway-start] RESET MODE: migrate:fresh + seed (test data)"
  php artisan migrate:fresh --force
  php artisan db:seed --force || echo "[railway-start] seed reported an error (continuing to serve)"
else
  echo "[railway-start] SAFE MODE: forward migrations only (no wipe)"
  if ! php artisan migrate --force; then
    if [ "$APP_ENV" = "production" ]; then
      # لا نخدم بمخطط ناقص في الإنتاج: نخرج بخطأ فيبقى Railway على النشر السابق السليم
      # (مع healthcheckPath في railway.json). (CROSS-11)
      echo "[railway-start] FATAL: migrate failed in production — refusing to serve (old deployment stays live)" >&2
      exit 1
    fi
    echo "[railway-start] migrate reported an error (non-production: continuing to serve)"
  fi
fi

echo "[railway-start] linking storage"
php artisan storage:link || true

mkdir -p storage/logs || true

if [ "${SCHEDULER_ENABLED:-true}" = "true" ]; then
  echo "[railway-start] starting scheduler (schedule:work) in background"
  (
    while true; do
      php artisan schedule:work >> storage/logs/schedule.log 2>&1 \
        || echo "[railway-start] schedule:work exited with code $? — restarting in 10s" >> storage/logs/schedule.log
      sleep 10
    done
  ) &
else
  echo "[railway-start] scheduler disabled (SCHEDULER_ENABLED=false)"
fi

# PHP built-in server: handle concurrent requests (default 8 workers).
export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-8}

echo "[railway-start] starting server on 0.0.0.0:${PORT:-8080} (workers: ${PHP_CLI_SERVER_WORKERS})"
php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
