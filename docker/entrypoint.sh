#!/bin/sh
set -eu

mkdir -p "${DATA_DIR:-/var/lib/air_quality}"

echo "Downloading EEA PM2.5 data (this may take a minute)..."
php /app/bin/ingest.php || echo "Initial ingest failed; the UI will show the error. Retrying on schedule."

php /app/bin/scheduler.php &

exec php -S "0.0.0.0:${APP_PORT:-8080}" -t /app/public /app/public/router.php
