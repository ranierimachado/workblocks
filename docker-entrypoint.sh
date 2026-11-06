#!/bin/sh
set -eu
mkdir -p /data/backups
touch /data/workblocks.sqlite
chown -R www-data:www-data /data
su-exec www-data php artisan migrate --force
exec "$@"
