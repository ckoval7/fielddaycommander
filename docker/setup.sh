#!/bin/bash
set -e

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

# Helper: set a key in the .env file if it's missing or empty
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        # Key exists — only replace if value is empty
        local current
        current=$(grep "^${key}=" "$ENV_FILE" | head -1 | cut -d'=' -f2-)
        if [[ -z "$current" || "$current" = '""' || "$current" = "''" ]]; then
            sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
            echo "  Generated $key"
        fi
    else
        echo "${key}=${value}" >> "$ENV_FILE"
        echo "  Generated $key"
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

# Database password
set_env "DB_PASSWORD" "$(random_string 32)"

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

echo ""
echo "Done! Review $ENV_FILE then run: docker compose up -d"
