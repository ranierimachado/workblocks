#!/bin/sh
set -eu
mkdir -p /data/backups storage/framework/sessions storage/framework/views storage/framework/cache/data
chown -R www-data:www-data storage/framework
touch /data/workblocks.sqlite
chown -R www-data:www-data /data
if [ "${WORKBLOCKS_SKIP_MIGRATION:-false}" != true ]; then
  su-exec www-data php artisan migrate --force
fi
exec "$@"
