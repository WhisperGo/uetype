#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
LOCK_FILE="$APP_DIR/storage/framework/deploy.lock"
MAINTENANCE_MODE=false

cd "$APP_DIR"

# Hindari dua proses deployment berjalan pada waktu yang sama.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Deployment lain masih berjalan."
    exit 1
fi

restore_application() {
    if [[ "$MAINTENANCE_MODE" == true ]]; then
        MAINTENANCE_MODE=false
        echo "Mengaktifkan kembali aplikasi..."
        php artisan up || true
    fi
}

trap restore_application EXIT INT TERM

echo "Mengaktifkan maintenance mode..."
php artisan down --retry=60
MAINTENANCE_MODE=true

echo "Mengambil kode terbaru..."
git pull --ff-only

echo "Memasang dependency PHP production..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "Menjalankan migrasi database..."
php artisan migrate --force

echo "Memasang dependency dan membangun aset frontend..."
# Vite berada di devDependencies dan tetap dibutuhkan untuk proses build production.
npm ci --include=dev
npm run build

echo "Membangun ulang cache Laravel..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

echo "Mengaktifkan kembali aplikasi..."
php artisan up
MAINTENANCE_MODE=false
trap - EXIT INT TERM

echo "Deployment selesai."