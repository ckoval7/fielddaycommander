#!/bin/bash
set -e

echo "=== FD Commander Docker Entrypoint ==="

# 0. Validate required environment
if [[ -z "$DB_PASSWORD" ]]; then
    echo "ERROR: DB_PASSWORD is not set." >&2
    echo "Run 'bash docker/setup.sh' to generate a .env file with secure defaults." >&2
    exit 1
fi

if [[ -z "$APP_KEY" || "$APP_KEY" = "base64:" ]]; then
    echo "ERROR: APP_KEY is not set." >&2
    echo "A key generated inside the container is lost on every restart, which" >&2
    echo "invalidates sessions and two-factor secrets. Run 'bash docker/setup.sh'" >&2
    echo "to add one to .env, then 'docker compose up -d' again." >&2
    exit 1
fi

# 1. The container runs as www-data and cannot fix ownership itself. Storage
#    written by an older root-run image, a root `docker exec`, or a bind mount
#    owned by another uid has to be handed over once from the host.
UNWRITABLE=$(find /app/storage /app/bootstrap/cache ! -writable -print -quit)
if [[ -n "$UNWRITABLE" ]]; then
    echo "ERROR: $UNWRITABLE is not writable by $(id -un) (uid $(id -u))." >&2
    echo "Hand the storage volume to www-data once, then start the app again:" >&2
    echo "  docker compose run --rm --no-deps -u root --entrypoint chown app -R www-data:www-data /app/storage" >&2
    echo "For a bind mount, run 'chown -R 33:33 <host dir>' on the host instead." >&2
    exit 1
fi

# 2. Ensure storage directory structure exists (volume may be fresh). The
#    public/storage symlink pointing into it is baked into the image.
echo "Setting up storage directories..."
mkdir -p /app/storage/{app/public,framework/{cache/data,sessions,views,testing},logs}

# 3. Build .env inside container from environment variables
#    Docker Compose injects env vars from env_file + environment directives.
#    Laravel commands like key:generate expect a .env file to exist.
echo "Writing .env from container environment..."
: > /app/.env
env | grep -E '^[A-Z_]+=' | sort | while IFS='=' read -r key value; do
    echo "${key}=\"${value}\"" >> /app/.env
done

# 4. Generate Reverb credentials if not set
if [[ -z "$REVERB_APP_KEY" ]]; then
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

# 5. Wait for database
echo "Waiting for database connection..."
until php artisan db:show > /dev/null 2>&1; do
    echo "  Database not ready, retrying in 3s..."
    sleep 3
done
echo "Database connected."

# 6. Run migrations
echo "Running migrations..."
php artisan migrate --force

# 7. Run production seeders on first run
SEEDER_MARKER="/app/storage/.seeders-complete"
if [[ ! -f "$SEEDER_MARKER" ]]; then
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

# 8. Cache configuration
echo "Optimizing application (config/route/view/event caches)..."
php artisan optimize

# 9. Keep the generated .env (database and Reverb secrets) private.
chmod 640 /app/.env

echo "=== Starting services via supervisord ==="
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
