#!/usr/bin/env sh

set -e

echo "Starting Laravel staging deployment..."

echo "Running database migrations..."
php artisan migrate --force

echo "Clearing stale Laravel caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "Caching Laravel configuration..."
php artisan config:cache

echo "Starting Laravel HTTP server on port ${PORT:-10000}..."
exec php artisan serve \
    --host=0.0.0.0 \
    --port="${PORT:-10000}"