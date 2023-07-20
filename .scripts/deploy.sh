#!/bin/bash
set -e

echo "Start deploying..."

cd /var/www/tldr.marcelwagner.dev

(php artisan down) || true

php artisan horizon:terminate

git stash

git reset --hard

git pull origin main

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

php artisan clear-compiled

php artisan optimize

npm install

npm run build

php artisan migrate --force

php artisan up

echo "Deployment finished!"
