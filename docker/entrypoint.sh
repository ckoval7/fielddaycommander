#!/bin/bash
set -e

echo "=== FD Commander Docker Entrypoint ==="

# 0. Validate required environment
if [ -z "$DB_PASSWORD" ]; then
    echo "ERROR: DB_PASSWORD is not set."
    echo "Run 'bash docker/setup.sh' to generate a .env file with secure defaults."
    exit 1
fi

# 1. Ensure storage directory structure exists (volume may be fresh)
echo "Setting up storage directories..."
mkdir -p /app/storage/{app/public,framework/{cache/data,sessions,views,testing},logs}
chmod -R 775 /app/storage /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache

# 2. Create storage symlink (idempotent)
php artisan storage:link 2>/dev/null || true

# 3. Wait for database
echo "Waiting for database connection..."
until php artisan migrate:status > /dev/null 2>&1; do
    echo "  Database not ready, retrying in 3s..."
    sleep 3
done
echo "Database connected."

# 4. Run migrations
echo "Running migrations..."
php artisan migrate --force

# 5. Run production seeders on first run
SEEDER_MARKER="/app/storage/.seeders-complete"
if [ ! -f "$SEEDER_MARKER" ]; then
    echo "First run detected — running production seeders..."
    php artisan db:seed --class=EventTypeSeeder --force
    php artisan db:seed --class=BandSeeder --force
    php artisan db:seed --class=ModeSeeder --force
    php artisan db:seed --class=SectionSeeder --force
    php artisan db:seed --class=OperatingClassSeeder --force
    php artisan db:seed --class=BonusTypeSeeder --force
    php artisan db:seed --class=PermissionSeeder --force
    php artisan db:seed --class=RoleSeeder --force
    php artisan db:seed --class=SystemAdminSeeder --force
    touch "$SEEDER_MARKER"
    echo "Seeders complete."
else
    echo "Seeders already ran (marker exists), skipping."
fi

# 6. Generate app key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "Generating application key..."
    php artisan key:generate --force
fi

# 7. Generate Reverb credentials if not set
if [ -z "$REVERB_APP_KEY" ]; then
    echo "Generating Reverb credentials..."
    REVERB_APP_KEY=$(head -c 20 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
    REVERB_APP_SECRET=$(head -c 20 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
    REVERB_APP_ID=$(head -c 8 /dev/urandom | base64 | tr -dc '0-9' | head -c 8)
    export REVERB_APP_KEY REVERB_APP_SECRET REVERB_APP_ID
fi

# 8. Cache configuration
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "=== Starting services via supervisord ==="
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
