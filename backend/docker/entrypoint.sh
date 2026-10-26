#!/bin/sh
set -e

# Attend la BD mutualisée puis applique les migrations (uniquement pour le service « backend »)
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Attente de PostgreSQL (${DB_HOST}:${DB_PORT})…"
  for i in $(seq 1 30); do
    php -r "try { new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', getenv('DB_PASSWORD')); exit(0); } catch (Exception \$e) { exit(1); }" && break
    sleep 2
  done
  php artisan migrate --force
  php artisan db:seed --force
fi

php artisan config:cache
exec "$@"
