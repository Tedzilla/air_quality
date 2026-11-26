#!/bin/sh
set -eu

mkdir -p "${DATA_DIR:-/var/lib/air_quality}"

# The first download can take several minutes (sensor.community history is one file per sensor per day),
# so it runs in the background and the web server starts right away.
(
    echo "Downloading PM2.5 data (EEA, then fallbacks for incomplete cities)..."
    php /app/bin/ingest.php || echo "Initial ingest failed; the UI will show the error. Retrying on schedule."
    exec php /app/bin/scheduler.php
) &

exec php -S "0.0.0.0:${APP_PORT:-8080}" -t /app/public /app/public/router.php
