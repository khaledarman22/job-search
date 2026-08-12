#!/bin/bash
set -e

echo "Deploying application..."

# Enter maintenance mode
php artisan down || true

# Update codebase
git pull origin main

# Install PHP dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Run database migrations
php artisan migrate --force

# Clear caches
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Install Node dependencies and build assets
npm ci
npm run build

# Restart queues
php artisan queue:restart || true

# Exit maintenance mode
php artisan up

echo "Application deployed!"
