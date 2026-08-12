#!/bin/bash
set -e

SERVER="ubuntu@54.93.107.177"
KEY="~/Downloads/SOMA.pem"
REMOTE_DIR="/var/www/job-search"

echo "Deploying to production..."

# Sync files (excluding git, node_modules, vendor, env, and public/hot)
rsync -avz --exclude '.git' --exclude 'node_modules' --exclude 'vendor' --exclude '.env' --exclude 'public/hot' -e "ssh -o StrictHostKeyChecking=no -i $KEY" ./ $SERVER:$REMOTE_DIR/

echo "Running post-deploy commands on server..."

ssh -o StrictHostKeyChecking=no -i $KEY $SERVER << 'SSHEOF'
  cd /var/www/job-search
  
  # Maintenance mode
  php artisan down || true
  
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
