#!/bin/sh
set -e

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

exec "$@"
