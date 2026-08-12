#!/bin/bash
set -e

SERVER="ubuntu@54.93.107.177"
KEY="~/Downloads/SOMA.pem"
REMOTE_DIR="/var/www/job-search"

echo "Deploying to production..."

# Sync files
rsync -avz --exclude '.git' --exclude 'node_modules' --exclude 'vendor' --exclude '.env' --exclude 'public/hot' --exclude 'bootstrap/cache/' -e "ssh -o StrictHostKeyChecking=no -i $KEY" ./ $SERVER:$REMOTE_DIR/

echo "Running post-deploy commands on server..."

ssh -o StrictHostKeyChecking=no -i $KEY $SERVER << 'SSHEOF'
  cd /var/www/job-search
  
  # Maintenance mode
  php artisan down || true
  
  # Clear old cache to avoid class not found errors during composer install
  rm -f bootstrap/cache/*.php
  
  # Ensure hot file is deleted so production serves built assets
  rm -f public/hot
  
  # Install PHP dependencies
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
  
  # Install Node dependencies and build
  npm ci
  npm run build
  
  # Run Migrations
  php artisan migrate --force
  
  # Clear and cache configurations
  php artisan optimize:clear
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  
  # Restart queues
  php artisan queue:restart || true
  
  # Exit Maintenance mode
  php artisan up
SSHEOF

echo "Deployed successfully!"
