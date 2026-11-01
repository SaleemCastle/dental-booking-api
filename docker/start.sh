#!/usr/bin/env sh

set -e

echo "Starting Laravel staging deployment..."

echo "Clearing stale Laravel caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "Running database migrations..."
php artisan migrate --force

if [ "${RUN_STAGING_SEEDER:-false}" = "true" ]; then
    echo "Running staging bootstrap seeder..."

    php artisan db:seed \
        --class=StagingSeeder \
        --force
fi

echo "Caching Laravel configuration..."
php artisan config:cache

echo "Starting Laravel HTTP server on port ${PORT:-10000}..."
exec php artisan serve \
    --host=0.0.0.0 \
    --port="${PORT:-10000}"