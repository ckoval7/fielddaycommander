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

usage() {
    cat <<'USAGE'
Usage: update.sh [options]

Updates an existing FD Commander deployment with the latest code.

Options:
  --app-path <path>     Deploy path (default: /var/www/fd-commander)
  --branch <branch>     Git branch (default: main)
  -h, --help            Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-path)  APP_PATH="$2"; shift 2 ;;
        --branch)    BRANCH="$2"; shift 2 ;;
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
fetch_updates() {
    log_phase "Checking for updates"

    if [[ "$DEPLOY_METHOD" == "git" ]]; then
        cd "$APP_PATH"
        sudo -u fdcommander git fetch origin "$BRANCH"
        LOCAL=$(sudo -u fdcommander git rev-parse HEAD)
        REMOTE=$(sudo -u fdcommander git rev-parse "origin/$BRANCH")
    else
        cd "$SCRIPT_DIR"
        git fetch origin "$BRANCH"
        LOCAL=$(git rev-parse HEAD)
        REMOTE=$(git rev-parse "origin/$BRANCH")
    fi

    if [[ "$LOCAL" == "$REMOTE" ]]; then
        log_info "Already up to date (${LOCAL:0:8}). Nothing to do."
        exit 0
    fi

    log_info "Updates available: ${LOCAL:0:8} → ${REMOTE:0:8}"
}

# --- Pull and sync ---
pull_updates() {
    log_phase "Pulling updates"

    if [[ "$DEPLOY_METHOD" == "git" ]]; then
        cd "$APP_PATH"
        sudo -u fdcommander git pull origin "$BRANCH"
        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH"
    else
        cd "$SCRIPT_DIR"
        git pull origin "$BRANCH"

        log_info "Syncing files to $APP_PATH..."
        rsync -a --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='vendor' \
            --exclude='.env' \
            --exclude='storage/app' \
            --exclude='storage/logs' \
            --exclude='storage/framework/sessions' \
            --exclude='storage/framework/cache/data' \
            "$SCRIPT_DIR/" "$APP_PATH/"

        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH"
    fi

    log_info "Code updated"
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
    sudo -u fdcommander php artisan config:cache
    sudo -u fdcommander php artisan route:cache
    sudo -u fdcommander php artisan view:cache
    log_info "Caches rebuilt"
}

# --- Restart services ---
restart_services() {
    log_phase "Restarting services"

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
