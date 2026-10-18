#!/bin/bash
set -e

# Files created here (.env) hold secrets, so create them owner-only.
umask 077

# Generates a .env file with secure defaults for Docker deployment.
# Run this once before 'docker compose up'.

ENV_FILE="${1:-.env}"
EXAMPLE_FILE=".env.example"

if [[ ! -f "$EXAMPLE_FILE" ]]; then
    echo "Error: $EXAMPLE_FILE not found. Run this script from the project root."
    exit 1
fi

if [[ -f "$ENV_FILE" ]]; then
    echo "Found existing $ENV_FILE — checking for missing secrets..."
    CREATED=false
else
    echo "Creating $ENV_FILE from $EXAMPLE_FILE..."
    cp "$EXAMPLE_FILE" "$ENV_FILE"
    CREATED=true
fi

# Whether docker compose already has a MySQL volume for this project. Best
# effort: without docker (or permission to use it) this reports no volume.
mysql_volume_exists() {
    command -v docker >/dev/null 2>&1 || return 1
    local project="${COMPOSE_PROJECT_NAME:-}"
    if [[ -z "$project" ]]; then
        project=$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
    fi
    docker volume inspect "${project}_mysql-data" >/dev/null 2>&1
}

# A new .env gets new random database passwords, but MySQL only reads them
# when its volume is first created. With the old volume still there, the app
# and backups could not log in.
if $CREATED && mysql_volume_exists; then
    echo ""
    echo "  WARNING: a MySQL volume from an earlier install still exists, and it only"
    echo "  accepts the database passwords it was created with. The new random"
    echo "  DB_PASSWORD and DB_ROOT_PASSWORD written to $ENV_FILE will not match it,"
    echo "  and the new APP_KEY will break two-factor sign-in for existing users."
    echo "  To keep your data, copy APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD and"
    echo "  REDIS_PASSWORD from your old .env (or a backup's env file) into $ENV_FILE."
    echo "  To start over with an empty database instead: docker compose down -v"
    echo ""
fi

# Helper: set a key in the .env file if it's missing or empty.
# An optional third argument replaces the "Generated <key>" message.
set_env() {
    local key="$1" value="$2" message="${3:-  Generated $1}"
    if grep -q "^${key}=" "$ENV_FILE"; then
        # Key exists — only replace if value is empty
        local current
        current=$(grep "^${key}=" "$ENV_FILE" | head -1 | cut -d'=' -f2-)
        if [[ -z "$current" || "$current" = '""' || "$current" = "''" || "$current" = "null" ]]; then
            sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
            echo "$message"
        fi
    else
        echo "${key}=${value}" >> "$ENV_FILE"
        echo "$message"
    fi
}

# Helper: generate a random alphanumeric string
random_string() {
    local length="${1:-32}"
    tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length" || true
}

# Pick a Redis maxmemory value sized to the host (Pi-friendly).
# Buckets by total RAM: ≤1G→128mb, ≤2G→256mb, ≤4G→512mb, ≤8G→1gb, else 2gb.
compute_redis_maxmemory() {
    local total_kb
    total_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
    local total_mb=$(( total_kb / 1024 ))

    if [[ $total_mb -le 1024 ]]; then
        echo "128mb"
    elif [[ $total_mb -le 2048 ]]; then
        echo "256mb"
    elif [[ $total_mb -le 4096 ]]; then
        echo "512mb"
    elif [[ $total_mb -le 8192 ]]; then
        echo "1gb"
    else
        echo "2gb"
    fi
}

echo ""
echo "Checking secrets..."

# Application key — must live in the host .env so it survives container
# restarts. Fortify encrypts two-factor secrets with it, and a backup restored
# without its key locks out every user with two-factor authentication.
set_env "APP_KEY" "base64:$(head -c 32 /dev/urandom | base64)"

# Database password
set_env "DB_PASSWORD" "$(random_string 32)"

# MySQL root password. backup.sh and restore.sh use it inside the mysql
# container. MySQL only reads it when the volume is first created, so an
# existing install keeps the old built-in default rather than a new value
# that would not match the database.
if ! $CREATED && ! grep -qE '^DB_ROOT_PASSWORD=.+' "$ENV_FILE"; then
    set_env "DB_ROOT_PASSWORD" "rootsecret" "  Set DB_ROOT_PASSWORD to the existing database's password"
    echo "  WARNING: DB_ROOT_PASSWORD kept at the old default 'rootsecret' because"
    echo "  the MySQL volume was created with it. To change it, run:"
    echo "    docker compose exec mysql mysql -uroot -prootsecret -e \"ALTER USER 'root'@'%' IDENTIFIED BY '<new>'; ALTER USER 'root'@'localhost' IDENTIFIED BY '<new>';\""
    echo "  then set DB_ROOT_PASSWORD=<new> in $ENV_FILE."
else
    set_env "DB_ROOT_PASSWORD" "$(random_string 32)"
fi

# Redis password (requirepass in docker-compose.yml)
set_env "REDIS_PASSWORD" "$(random_string 32)"

# Reverb WebSocket credentials
set_env "REVERB_APP_KEY" "$(random_string 20)"
set_env "REVERB_APP_SECRET" "$(random_string 20)"
set_env "REVERB_APP_ID" "$(random_string 8)"

# External logger UDP ports — uncomment and change to use non-default ports.
# These must match the ports configured in the FD Commander admin UI
# (Settings > External Loggers) and the mappings in docker-compose.yml.
# set_env "N1MM_PORT" "12060"       # N1MM Logger+  (default: 12060)
# set_env "WSJTX_PORT" "2237"       # WSJT-X / JTDX (default: 2237)
# set_env "UDP_ADIF_PORT" "2238"    # Plain UDP ADIF (default: 2238)

# Docker-specific defaults
if $CREATED; then
    echo ""
    echo "Setting Docker defaults..."
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" "$ENV_FILE"
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" "$ENV_FILE"
    sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" "$ENV_FILE"
    sed -i "s|^BROADCAST_CONNECTION=.*|BROADCAST_CONNECTION=reverb|" "$ENV_FILE"
    # Redis-backed cache/session/queue — fast in RAM, durable on disk via AOF.
    sed -i "s|^CACHE_STORE=.*|CACHE_STORE=redis|" "$ENV_FILE"
    sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" "$ENV_FILE"
    sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" "$ENV_FILE"
    set_env "REDIS_CLIENT" "phpredis"
    set_env "REDIS_HOST" "redis"
    set_env "REDIS_PORT" "6379"
    set_env "REDIS_DB" "0"
    set_env "REDIS_CACHE_DB" "1"
    # Sized to host RAM so Pi-class machines don't starve the app.
    set_env "REDIS_MAXMEMORY" "$(compute_redis_maxmemory)"
fi

# Rotate logs daily (kept LOG_DAILY_DAYS days) instead of one ever-growing
# file. Existing installs still on the old "single" default are switched once;
# LOG_DAILY_DAYS marks that this has been done.
if ! grep -qE '^LOG_DAILY_DAYS=' "$ENV_FILE"; then
    if grep -qE '^LOG_STACK=("?single"?)?$' "$ENV_FILE"; then
        sed -i 's|^LOG_STACK=.*|LOG_STACK=daily|' "$ENV_FILE"
        echo "  Switched LOG_STACK to daily"
    fi
    set_env "LOG_DAILY_DAYS" "14"
fi

# The file holds APP_KEY and every password, so keep it owner-only.
chmod 600 "$ENV_FILE"

echo ""
echo "Done! Review $ENV_FILE then run: docker compose up -d"
