#!/bin/sh
set -e
if [ ! -f "$MINIRANK_DB_PATH.seeded" ] || [ ! -s "$MINIRANK_DB_PATH" ]; then
    php bin/seed.php
    touch "$MINIRANK_DB_PATH.seeded"
fi
exec php -S 0.0.0.0:8000 -t public