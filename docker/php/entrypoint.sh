#!/bin/sh
set -e

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

echo "Setting up messenger transports..."
php bin/console messenger:setup-transports --no-interaction || true

exec "$@"
