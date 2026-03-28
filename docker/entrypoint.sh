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
if [ ! -L /app/public/storage ]; then
    php artisan storage:link
fi

# 3. Build .env inside container from environment variables
#    Docker Compose injects env vars from env_file + environment directives.
#    Laravel commands like key:generate expect a .env file to exist.
echo "Writing .env from container environment..."
: > /app/.env
env | grep -E '^[A-Z_]+=' | sort | while IFS='=' read -r key value; do
    echo "${key}=\"${value}\"" >> /app/.env
done

# 4. Generate APP_KEY if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "Generating application key..."
    php artisan key:generate --force
    # Re-export so downstream commands see it
    export APP_KEY=$(grep '^APP_KEY=' /app/.env | cut -d= -f2-)
fi

# 5. Generate Reverb credentials if not set
if [ -z "$REVERB_APP_KEY" ]; then
    echo "Generating Reverb credentials..."
    REVERB_APP_KEY=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 20 || true)
    REVERB_APP_SECRET=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 20 || true)
    REVERB_APP_ID=$(tr -dc '0-9' < /dev/urandom | head -c 8 || true)
    export REVERB_APP_KEY REVERB_APP_SECRET REVERB_APP_ID
    # Append to .env so artisan commands pick them up
    echo "REVERB_APP_KEY=$REVERB_APP_KEY" >> /app/.env
    echo "REVERB_APP_SECRET=$REVERB_APP_SECRET" >> /app/.env
    echo "REVERB_APP_ID=$REVERB_APP_ID" >> /app/.env
fi

# 6. Wait for database
echo "Waiting for database connection..."
until php artisan db:show > /dev/null 2>&1; do
    echo "  Database not ready, retrying in 3s..."
    sleep 3
done
echo "Database connected."

# 7. Run migrations
echo "Running migrations..."
php artisan migrate --force

# 8. Run production seeders on first run
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

# 9. Cache configuration
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "=== Starting services via supervisord ==="
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
