#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Wait until MySQL accepts TCP connections
if [ -n "${DB_HOST:-}" ]; then
  echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
  until php -r "new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" >/dev/null 2>&1; do
    sleep 2
  done
  echo "MySQL is ready."
fi

# Run migrations and initial seed once from the API container (skip duplicate run in queue container)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan storage:link --force >/dev/null 2>&1 || true
  php artisan migrate --force

  USER_COUNT=$(php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo Illuminate\Support\Facades\DB::table("users")->count();
  ' 2>/dev/null || echo "0")

  if [ "${USER_COUNT}" = "0" ]; then
    echo "Empty database detected — seeding SHOP.CO catalog, RBAC, reviews, and demo orders..."
    php artisan db:seed --force
  fi
fi

exec "$@"
