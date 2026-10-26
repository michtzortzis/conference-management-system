#!/usr/bin/env sh
set -eu

# Render provides an external HTTPS URL; use it when APP_URL is unset.
if [ -z "${APP_URL:-}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="${RENDER_EXTERNAL_URL}"
fi

# Run migrations on startup (free tier: no preDeployCommand support).
php artisan migrate --force --seed

# Start optional side processes in the same container when needed.
if [ "${ENABLE_SSR:-false}" = "true" ]; then
    php artisan inertia:start-ssr &
fi

if [ "${RUN_QUEUE_WORKER:-false}" = "true" ]; then
    php artisan queue:work --tries="${QUEUE_TRIES:-3}" --sleep="${QUEUE_SLEEP:-3}" --timeout="${QUEUE_TIMEOUT:-120}" &
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
