#!/usr/bin/env bash
set -euo pipefail

# --- Constants ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/fd-commander-update.log"

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# --- Early root check ---
if [[ $EUID -ne 0 ]]; then
    echo -e "\033[0;31m[ERROR]\033[0m This script must be run as root (or via sudo)"
    exit 1
fi

# --- Logging ---
log_info()    { echo -e "${GREEN}[INFO]${NC} $1" | tee -a "$LOG_FILE"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $1" | tee -a "$LOG_FILE"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"; }
log_phase()   { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}" | tee -a "$LOG_FILE"; }

# Redirect all stdout/stderr to log file as well
exec > >(tee -a "$LOG_FILE") 2>&1

# --- Defaults ---
APP_PATH="/var/www/fd-commander"
BRANCH="main"
FORCE=0
NO_REDIS=0
WITH_REDIS=0

usage() {
    cat <<'USAGE'
Usage: update.sh [options]

Updates an existing FD Commander deployment with the latest code.

Options:
  --app-path <path>     Deploy path (default: /var/www/fd-commander)
  --branch <branch>     Git branch (default: main)
  --force               Run full update pipeline even if already up to date
  --no-redis            Skip Redis install and .env driver migration
                        (keep existing cache/session/queue drivers)
  --with-redis          Install Redis and migrate drivers without prompting
                        (use in non-interactive automation)
  -h, --help            Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-path)  APP_PATH="$2"; shift 2 ;;
        --branch)    BRANCH="$2"; shift 2 ;;
        --force)     FORCE=1; shift ;;
        --no-redis)  NO_REDIS=1; shift ;;
        --with-redis) WITH_REDIS=1; shift ;;
        -h|--help)   usage 0 ;;
        *)           log_error "Unknown option: $1"; usage 1 ;;
    esac
done

# --- Validation ---
if [[ ! -d "$APP_PATH" ]]; then
    log_error "App path does not exist: $APP_PATH"
    log_error "Has FD Commander been deployed? Run deploy.sh first."
    exit 1
fi

if [[ ! -f "$APP_PATH/artisan" ]]; then
    log_error "No Laravel application found at $APP_PATH"
    exit 1
fi

# Detect web group from existing ownership
WEB_GROUP=$(stat -c '%G' "$APP_PATH")

# Detect distro family for package installs (redis bootstrap on old installs).
DISTRO_FAMILY=""
PKG_MANAGER=""
REDIS_SERVICE=""
REDIS_PKG=""
PHP_REDIS_PKG=""
if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    if [[ "$ID" == "ubuntu" ]] || [[ "$ID" == "debian" ]] || [[ "$ID" == "raspbian" ]]; then
        DISTRO_FAMILY="debian"
        PKG_MANAGER="apt"
        REDIS_SERVICE="redis-server"
        REDIS_PKG="redis-server"
        PHP_REDIS_PKG="php8.4-redis"
    elif [[ "$ID" == "rhel" ]] || [[ "$ID" == "almalinux" ]] || [[ "$ID" == "rocky" ]] || [[ "${ID_LIKE:-}" == *"rhel"* ]] || [[ "${ID_LIKE:-}" == *"fedora"* ]]; then
        DISTRO_FAMILY="rhel"
        PKG_MANAGER="dnf"
        REDIS_SERVICE="redis"
        REDIS_PKG="redis"
        PHP_REDIS_PKG="php-redis"
    fi
fi

# --- Determine deploy method ---
if [[ -d "$APP_PATH/.git" ]]; then
    DEPLOY_METHOD="git"
    log_info "Detected git-based deployment at $APP_PATH"
else
    DEPLOY_METHOD="rsync"
    log_info "Detected rsync-based deployment (source: $SCRIPT_DIR)"

    if [[ ! -d "$SCRIPT_DIR/.git" ]]; then
        log_error "Source directory is not a git repository: $SCRIPT_DIR"
        exit 1
    fi
fi

# --- Fetch and check for updates ---
DEPLOYED_REV_FILE="$APP_PATH/.deployed-revision"

fetch_updates() {
    log_phase "Checking for updates"

    if [[ "$DEPLOY_METHOD" == "git" ]]; then
        cd "$APP_PATH"
        sudo -u fdcommander git fetch origin "$BRANCH"
        LOCAL=$(sudo -u fdcommander git rev-parse HEAD)
        REMOTE=$(sudo -u fdcommander git rev-parse "origin/$BRANCH")

        if [[ "$LOCAL" == "$REMOTE" ]]; then
            if [[ $FORCE -eq 1 ]]; then
                log_warn "Already up to date (${LOCAL:0:8}) — continuing due to --force"
            else
                log_info "Already up to date (${LOCAL:0:8}). Nothing to do."
                exit 0
            fi
        else
            log_info "Updates available: ${LOCAL:0:8} → ${REMOTE:0:8}"
        fi
    else
        cd "$SCRIPT_DIR"
        git fetch origin "$BRANCH"

        # Pull source to latest if behind remote
        LOCAL=$(git rev-parse HEAD)
        REMOTE=$(git rev-parse "origin/$BRANCH")
        if [[ "$LOCAL" != "$REMOTE" ]]; then
            log_info "Source repo is behind remote, pulling..."
            git pull origin "$BRANCH"
        fi

        # Compare source HEAD against last deployed revision
        SOURCE_REV=$(git rev-parse HEAD)
        DEPLOYED_REV=""
        if [[ -f "$DEPLOYED_REV_FILE" ]]; then
            DEPLOYED_REV=$(cat "$DEPLOYED_REV_FILE")
        fi

        if [[ "$SOURCE_REV" == "$DEPLOYED_REV" ]]; then
            if [[ $FORCE -eq 1 ]]; then
                log_warn "Already up to date (${SOURCE_REV:0:8}) — continuing due to --force"
            else
                log_info "Already up to date (${SOURCE_REV:0:8}). Nothing to do."
                exit 0
            fi
        elif [[ -n "$DEPLOYED_REV" ]]; then
            log_info "Updates available: ${DEPLOYED_REV:0:8} → ${SOURCE_REV:0:8}"
        else
            log_info "No deployment revision tracked yet — will sync current source (${SOURCE_REV:0:8})"
        fi
    fi
}

# --- Pull and sync ---
pull_updates() {
    log_phase "Pulling updates"

    if [[ "$DEPLOY_METHOD" == "git" ]]; then
        cd "$APP_PATH"
        sudo -u fdcommander git pull origin "$BRANCH"
        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH"
    else
        log_info "Syncing files to $APP_PATH..."
        rsync -a --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='vendor' \
            --exclude='.env' \
            --exclude='.deployed-revision' \
            --exclude='public/storage' \
            --exclude='storage/app' \
            --exclude='storage/logs' \
            --exclude='storage/framework/sessions' \
            --exclude='storage/framework/cache/data' \
            "$SCRIPT_DIR/" "$APP_PATH/"

        # Record the deployed revision
        cd "$SCRIPT_DIR"
        git rev-parse HEAD > "$DEPLOYED_REV_FILE"

        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH"
    fi

    # Ensure storage symlink exists
    if [[ ! -L "$APP_PATH/public/storage" ]]; then
        cd "$APP_PATH"
        sudo -u fdcommander php artisan storage:link
        log_info "Recreated storage symlink"
    fi

    log_info "Code updated"
}

# Pick an Octane worker count sized to the host.
# Defaults to nproc, with a floor of 2 so even a single-core box can handle
# a polling request alongside a user request.
compute_octane_workers() {
    local cores
    cores=$(nproc 2>/dev/null || echo 1)
    if [[ $cores -lt 2 ]]; then
        echo 2
    else
        echo "$cores"
    fi
    return 0
}

# Sync the FrankenPHP/Octane systemd unit's --workers value with current nproc.
# Idempotent: no-ops if the unit already specifies the desired count.
# Adds the flag if missing (older installs).
sync_octane_workers() {
    local unit_file="/etc/systemd/system/fdcommander.service"
    if [[ ! -f "$unit_file" ]]; then
        log_warn "Octane unit not found at ${unit_file} — skipping worker sync"
        return 0
    fi

    local desired
    desired=$(compute_octane_workers)

    if grep -qE -- '--workers=[0-9]+' "$unit_file"; then
        local current
        current=$(grep -oE -- '--workers=[0-9]+' "$unit_file" | head -1 | cut -d= -f2)
        if [[ "$current" == "$desired" ]]; then
            log_info "Octane workers already at ${desired}"
            return 0
        fi
        log_info "Updating Octane workers in unit: ${current} → ${desired}"
        sed -i -E "s/--workers=[0-9]+/--workers=${desired}/" "$unit_file"
    else
        log_info "Adding --workers=${desired} to Octane unit"
        sed -i -E "s|(octane:frankenphp)( --host=[^ ]+)?( --port=[^ ]+)?|\1\2\3 --workers=${desired}|" "$unit_file"
    fi

    systemctl daemon-reload
    return 0
}

# Pick a Redis maxmemory value sized to the host (Pi-friendly).
# Buckets by total RAM: ≤1G→128mb, ≤2G→256mb, ≤4G→512mb, ≤8G→1gb, else 2gb.
compute_redis_maxmemory() {
    local total_kb
    total_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
    local total_mb=$(( total_kb / 1024 ))
    if   [[ $total_mb -le 1024 ]]; then echo "128mb"
    elif [[ $total_mb -le 2048 ]]; then echo "256mb"
    elif [[ $total_mb -le 4096 ]]; then echo "512mb"
    elif [[ $total_mb -le 8192 ]]; then echo "1gb"
    else                                 echo "2gb"
    fi
    return 0
}

# set_env: idempotently set a key=value in the deployed .env file.
set_env() {
    local env_file="$APP_PATH/.env"
    local key="$1" value="$2"
    sed -i "/^#\?${key}=/d" "$env_file"
    echo "${key}=${value}" >> "$env_file"
    return 0
}

# Ask the user whether to migrate to Redis on first run. Returns 0 = migrate,
# 1 = skip. Non-interactive runs skip with a warning (use --with-redis or
# --no-redis to silence).
prompt_redis_migration() {
    # Already have a definitive answer from CLI flags — don't prompt.
    [[ $WITH_REDIS -eq 1 ]] && return 0
    [[ $NO_REDIS   -eq 1 ]] && return 1

    # Redis already present — nothing to prompt about.
    if systemctl is-enabled "${REDIS_SERVICE}.service" &>/dev/null \
        || systemctl is-active "${REDIS_SERVICE}.service" &>/dev/null; then
        return 0
    fi

    # Non-interactive (cron, pipe, no TTY) — default to skip, tell the user.
    if [[ ! -t 0 ]] || [[ ! -t 1 ]]; then
        log_warn "Redis not installed and no TTY available for prompt."
        log_warn "Skipping Redis migration. Re-run with --with-redis or --no-redis to silence."
        return 1
    fi

    echo ""
    echo -e "${BOLD}Switch to Redis for better performance?${NC}"
    echo "  Redis is a small helper program that speeds up the app by keeping"
    echo "  frequently used data in memory instead of asking the database every"
    echo "  time."
    echo ""
    echo -e "  ${GREEN}What you gain:${NC}"
    echo "    • Noticeably snappier app, especially on busy Field Day stations"
    echo "    • Less strain on the database"
    echo ""
    echo -e "  ${YELLOW}What to know:${NC}"
    echo "    • One more background program runs on the server"
    echo "    • Uses some extra memory (sized automatically — as little as"
    echo "      128 MB on a Raspberry Pi, up to 2 GB on a bigger machine)"
    echo "    • Everyone logged in will need to sign in again once after the"
    echo "      switch"
    echo ""

    local reply
    read -r -p "Install Redis and migrate drivers? [y/N] " reply </dev/tty
    case "${reply,,}" in
        y|yes) return 0 ;;
        *)     log_info "Keeping existing drivers — re-run with --with-redis later to migrate."
               return 1 ;;
    esac
}

# --- Install and configure Redis on old installs that don't have it yet ---
bootstrap_redis() {
    if systemctl is-enabled "${REDIS_SERVICE}.service" &>/dev/null \
        || systemctl is-active "${REDIS_SERVICE}.service" &>/dev/null; then
        return 0
    fi

    log_phase "Bootstrapping Redis (missing from this install)"

    if [[ -z "$DISTRO_FAMILY" ]]; then
        log_warn "Unknown distro — skipping Redis install. Install redis manually and re-run."
        return 0
    fi

    if ! command -v redis-cli &>/dev/null; then
        log_info "Installing ${REDIS_PKG}..."
        if [[ "$PKG_MANAGER" == "apt" ]]; then
            apt-get update -y
            apt-get install -y "$REDIS_PKG" "$PHP_REDIS_PKG"
        else
            dnf install -y "$REDIS_PKG" "$PHP_REDIS_PKG"
        fi
    fi

    local override="/etc/redis/fdcommander.conf"
    local conf_file="/etc/redis/redis.conf"
    [[ ! -f "$conf_file" && -f "/etc/redis.conf" ]] && conf_file="/etc/redis.conf"
    if [[ ! -f "$conf_file" ]]; then
        log_error "Redis config not found after install"
        return 1
    fi

    local redis_maxmemory
    redis_maxmemory=$(compute_redis_maxmemory)
    log_info "Redis maxmemory sized for host: ${redis_maxmemory}"

    mkdir -p "$(dirname "$override")"
    cat > "$override" <<REDISEOF
# FD Commander Redis tuning — durable queue + heavy RAM cache.
appendonly yes
appendfsync always
no-appendfsync-on-rewrite no
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
aof-use-rdb-preamble yes

save 900 1
save 300 10
save 60 10000

maxmemory ${redis_maxmemory}
maxmemory-policy volatile-lru

tcp-keepalive 60
REDISEOF

    if ! grep -q "^include ${override}" "$conf_file"; then
        echo "" >> "$conf_file"
        echo "# FD Commander overrides" >> "$conf_file"
        echo "include ${override}" >> "$conf_file"
    fi

    systemctl enable "$REDIS_SERVICE"
    systemctl restart "$REDIS_SERVICE"
    if ! redis-cli ping | grep -q PONG; then
        log_error "Redis did not respond to PING after restart"
        return 1
    fi
    log_info "Redis configured with AOF fsync=always and volatile-lru eviction"
}

# Point .env at redis drivers if this deployment is still using the old
# database/file drivers. Leaves unrelated settings alone.
migrate_env_to_redis() {
    local env_file="$APP_PATH/.env"
    [[ -f "$env_file" ]] || return 0

    local changed=false
    for pair in "CACHE_STORE=redis" "SESSION_DRIVER=redis" "QUEUE_CONNECTION=redis"; do
        local key="${pair%=*}" want="${pair#*=}"
        local current
        current=$(grep -E "^${key}=" "$env_file" | head -1 | cut -d= -f2-)
        current="${current%\"}"; current="${current#\"}"
        if [[ "$current" != "$want" ]]; then
            set_env "$key" "$want"
            changed=true
        fi
    done

    # Fill in Redis connection defaults if absent (don't overwrite existing values).
    grep -qE '^REDIS_CLIENT=' "$env_file"   || { set_env "REDIS_CLIENT" "phpredis"; changed=true; }
    grep -qE '^REDIS_HOST='   "$env_file"   || { set_env "REDIS_HOST" "127.0.0.1";  changed=true; }
    grep -qE '^REDIS_PORT='   "$env_file"   || { set_env "REDIS_PORT" "6379";       changed=true; }

    if $changed; then
        chown "fdcommander:${WEB_GROUP}" "$env_file"
        log_info ".env migrated to Redis drivers"
    fi
}

# --- Install dependencies ---
install_dependencies() {
    log_phase "Installing dependencies"

    cd "$APP_PATH"

    log_info "Installing Composer dependencies..."
    sudo -u fdcommander COMPOSER_HOME="$APP_PATH/.composer" \
        /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

    log_info "Installing Node dependencies and building assets..."
    sudo -u fdcommander HOME="$APP_PATH" npm ci --cache "$APP_PATH/.npm"
    sudo -u fdcommander HOME="$APP_PATH" npm run build
}

# --- Run migrations ---
run_migrations() {
    log_phase "Running migrations"

    cd "$APP_PATH"
    sudo -u fdcommander php artisan migrate --force
    log_info "Migrations complete"
}

# --- Rebuild caches ---
rebuild_caches() {
    log_phase "Rebuilding caches"

    cd "$APP_PATH"
    sudo -u fdcommander php artisan optimize
    log_info "Caches rebuilt"
}

# --- Restart services ---
restart_services() {
    log_phase "Restarting services"

    sync_octane_workers

    systemctl restart fdcommander.service
    systemctl restart fdcommander-queue.service
    systemctl restart fdcommander-reverb.service

    log_info "Services restarted"
}

# --- Main ---
main() {
    log_phase "FD Commander Update — $(date '+%Y-%m-%d %H:%M:%S')"

    fetch_updates
    pull_updates
    if prompt_redis_migration; then
        bootstrap_redis
        migrate_env_to_redis
    else
        log_warn "Skipping Redis bootstrap and .env driver migration"
    fi
    install_dependencies
    run_migrations
    rebuild_caches
    restart_services

    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}  FD Commander Update Complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    echo -e "  Version:  $(cd "$APP_PATH" && sudo -u fdcommander git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
    echo -e "  App URL:  $(grep '^APP_URL=' "$APP_PATH/.env" | cut -d= -f2-)"
    echo ""
    echo -e "${BOLD}Service Status${NC}"
    echo "  FrankenPHP:   $(systemctl is-active fdcommander.service)"
    echo "  Queue Worker: $(systemctl is-active fdcommander-queue.service)"
    echo "  Reverb:       $(systemctl is-active fdcommander-reverb.service)"
    echo ""
    echo "  Update log:   ${LOG_FILE}"
    echo ""
}

main
