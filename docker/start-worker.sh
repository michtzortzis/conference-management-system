#!/usr/bin/env sh
set -eu

exec php artisan queue:work --tries="${QUEUE_TRIES:-3}" --sleep="${QUEUE_SLEEP:-3}" --timeout="${QUEUE_TIMEOUT:-120}" --max-time="${QUEUE_MAX_TIME:-3600}"
