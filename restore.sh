#!/usr/bin/env bash
set -euo pipefail

# --- Constants ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/fd-commander-restore.log"
SERVICES=(fdcommander fdcommander-queue fdcommander-scheduler fdcommander-reverb)

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# --- Early root check ---
if [[ $EUID -ne 0 ]]; then
    echo -e "\033[0;31m[ERROR]\033[0m This script must be run as root (or via sudo)" >&2
    exit 1
fi

# --- Logging ---
log_info()    { local message="$1"; echo -e "${GREEN}[INFO]${NC} ${message}" | tee -a "$LOG_FILE"; }
log_warn()    { local message="$1"; echo -e "${YELLOW}[WARN]${NC} ${message}" | tee -a "$LOG_FILE"; }
log_error()   { local message="$1"; echo -e "${RED}[ERROR]${NC} ${message}" | tee -a "$LOG_FILE" >&2; }
log_phase()   { local title="$1"; echo -e "\n${CYAN}${BOLD}=== ${title} ===${NC}" | tee -a "$LOG_FILE"; }

# Redirect all stdout/stderr to log file as well
exec > >(tee -a "$LOG_FILE") 2>&1

# --- Defaults ---
APP_PATH="/var/www/fd-commander"
DOCKER=0
COMPOSE_DIR="."
DB_ONLY=0
ASSUME_YES=0
NEW_SERVER=0
DB_BACKUP=""
PRE_RESTORE_DB=""

usage() {
    cat <<'USAGE'
Usage: restore.sh <path/to/fdc-db-STAMP.sql.gz> [options]

Restores an FD Commander backup made by backup.sh. The matching
fdc-files-STAMP.tar.gz and fdc-env-STAMP must sit next to the database file.
A restore replaces all current data, so a fresh backup is taken first.

Mode:
  --app-path <path>     Native install path (default: /var/www/fd-commander)
  --docker              Restore into a Docker Compose install instead
  --compose-dir <dir>   Directory holding docker-compose.yml and .env
                        (default: current directory)

Options:
  --db-only             Restore only the database, not storage/app
  --new-server          Native only: copy APP_KEY and MAIL_* settings from the
                        backup's env file into this server's .env. Use this
                        when restoring onto a different machine.
  --yes                 Skip the confirmation prompt
  -h, --help            Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-path)     APP_PATH="$2"; shift 2 ;;
        --docker)       DOCKER=1; shift ;;
        --compose-dir)  COMPOSE_DIR="$2"; shift 2 ;;
        --db-only)      DB_ONLY=1; shift ;;
        --new-server)   NEW_SERVER=1; shift ;;
        --yes)          ASSUME_YES=1; shift ;;
        -h|--help)      usage 0 ;;
        -*)             log_error "Unknown option: $1"; usage 1 ;;
        *)
            if [[ -n "$DB_BACKUP" ]]; then
                log_error "Only one backup file can be restored at a time"
                usage 1
            fi
            DB_BACKUP="$1"; shift ;;
    esac
done

# --- Validation ---
if [[ -z "$DB_BACKUP" ]]; then
    log_error "Pass the fdc-db-*.sql.gz file to restore"
    usage 1
fi

if [[ ! -f "$DB_BACKUP" ]]; then
    log_error "Backup file not found: $DB_BACKUP"
    exit 1
fi

DB_BACKUP="$(realpath "$DB_BACKUP")"
BACKUP_DIR="$(dirname "$DB_BACKUP")"
if [[ ! "$(basename "$DB_BACKUP")" =~ ^fdc-db-([0-9]{8}-[0-9]{6})\.sql\.gz$ ]]; then
    log_error "Not a backup.sh database file (expected fdc-db-YYYYMMDD-HHMMSS.sql.gz): $DB_BACKUP"
    exit 1
fi
STAMP="${BASH_REMATCH[1]}"
FILES_BACKUP="$BACKUP_DIR/fdc-files-${STAMP}.tar.gz"
ENV_BACKUP="$BACKUP_DIR/fdc-env-${STAMP}"

if [[ $DB_ONLY -eq 0 && ! -f "$FILES_BACKUP" ]]; then
    log_error "Missing $FILES_BACKUP"
    log_error "This looks like a database-only backup. Re-run with --db-only to restore just the database."
    exit 1
fi

if [[ $NEW_SERVER -eq 1 ]]; then
    if [[ $DOCKER -eq 1 ]]; then
        log_error "--new-server is for native installs. For Docker, copy APP_KEY into .env by hand (see DOCKER.md)."
        exit 1
    fi
    if [[ ! -f "$ENV_BACKUP" ]]; then
        log_error "--new-server needs $ENV_BACKUP, which is missing"
        exit 1
    fi
fi

if [[ $DOCKER -eq 1 ]]; then
    if [[ ! -f "$COMPOSE_DIR/docker-compose.yml" ]]; then
        log_error "No docker-compose.yml in $COMPOSE_DIR — pass --compose-dir"
        exit 1
    fi
    COMPOSE_DIR="$(cd "$COMPOSE_DIR" && pwd)"
    CURRENT_ENV="$COMPOSE_DIR/.env"
    MODE_ARGS=(--docker --compose-dir "$COMPOSE_DIR")
    PRE_RESTORE_DIR="$COMPOSE_DIR/backups/pre-restore-$(date +%Y%m%d-%H%M%S)"
else
    if [[ ! -f "$APP_PATH/artisan" ]]; then
        log_error "No FD Commander install found at $APP_PATH — pass --app-path"
        exit 1
    fi
    APP_PATH="$(cd "$APP_PATH" && pwd)"
    CURRENT_ENV="$APP_PATH/.env"
    MODE_ARGS=(--app-path "$APP_PATH")
    PRE_RESTORE_DIR="/var/backups/fd-commander/pre-restore-$(date +%Y%m%d-%H%M%S)"
fi

# env_value: print a key's value from a .env file, without surrounding quotes.
env_value() {
    local file="$1" key="$2" value
    value=$(grep -E "^${key}=" "$file" | tail -1 | cut -d= -f2-)
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    echo "$value"
    return 0
}

# --- Failure reporting ---
RESTORE_STARTED=0
SERVICES_STOPPED=0
CURRENT_STEP=""
COMPLETED_STEPS=()

begin_step() {
    [[ -n "$CURRENT_STEP" ]] && COMPLETED_STEPS+=("$CURRENT_STEP")
    local step="$1"
    CURRENT_STEP="$step"
    log_info "${step}..."
    return 0
}

on_exit() {
    local status=$?
    [[ $status -eq 0 || $RESTORE_STARTED -eq 0 ]] && return 0

    local undo_args=("${MODE_ARGS[@]}")
    [[ $NEW_SERVER -eq 1 ]] && undo_args+=(--new-server)

    echo ""
    log_error "Restore stopped during: ${CURRENT_STEP}"
    if [[ ${#COMPLETED_STEPS[@]} -gt 0 ]]; then
        log_error "Steps that completed:"
        local step
        for step in "${COMPLETED_STEPS[@]}"; do
            log_error "  - ${step}"
        done
    fi
    if [[ $SERVICES_STOPPED -eq 1 ]]; then
        log_error "The application is stopped and its data may be partly restored."
    else
        log_error "The application's data may be partly restored."
    fi
    log_error "Pre-restore backup: ${PRE_RESTORE_DIR}"
    log_error "To undo, restore it with:"
    log_error "  ${SCRIPT_DIR}/restore.sh ${PRE_RESTORE_DB} ${undo_args[*]}"
    log_error "Full log: ${LOG_FILE}"
    return 0
}
trap on_exit EXIT

# --- Checks before anything changes ---

# dump_database_name: the database the dump recreates (backup.sh dumps with
# --databases, so the dump carries its own CREATE DATABASE / USE).
dump_database_name() {
    local name
    # shellcheck disable=SC2016 # backticks are literal in the sed pattern
    name=$( { gunzip -c "$DB_BACKUP" || true; } | head -n 100 \
        | sed -nE 's/^CREATE DATABASE [^`]*`([^`]+)`.*/\1/p' | head -1 || true)
    echo "$name"
    return 0
}

target_database_name() {
    if [[ $DOCKER -eq 1 ]]; then
        # shellcheck disable=SC2016 # expanded by the shell inside the container
        (cd "$COMPOSE_DIR" && docker compose exec -T mysql sh -c 'echo "$MYSQL_DATABASE"')
    else
        env_value "$APP_PATH/.env" DB_DATABASE
    fi
    return 0
}

preflight() {
    log_phase "Checking backup"

    log_info "Testing ${DB_BACKUP}..."
    gzip -t "$DB_BACKUP"
    if [[ $DB_ONLY -eq 0 ]]; then
        log_info "Testing ${FILES_BACKUP}..."
        tar -tzf "$FILES_BACKUP" > /dev/null
    fi

    DUMP_DB=$(dump_database_name)
    TARGET_DB=$(target_database_name)
    if [[ -z "$DUMP_DB" ]]; then
        log_error "Could not find a CREATE DATABASE statement in $DB_BACKUP"
        exit 1
    fi
    if [[ -z "$TARGET_DB" ]]; then
        log_error "Could not determine the current database name"
        exit 1
    fi
    if [[ "$DUMP_DB" != "$TARGET_DB" ]]; then
        log_error "The backup is of database '${DUMP_DB}', but this install uses '${TARGET_DB}'."
        log_error "Set DB_DATABASE to '${DUMP_DB}' (and grant the app user access to it) before restoring."
        exit 1
    fi

    if [[ ! -f "$ENV_BACKUP" ]]; then
        log_warn "No env file for this backup ($ENV_BACKUP)."
        log_warn "If APP_KEY changed since the backup, users with two-factor authentication will be locked out."
    elif [[ -f "$CURRENT_ENV" && $NEW_SERVER -eq 0 ]] \
        && [[ "$(env_value "$ENV_BACKUP" APP_KEY)" != "$(env_value "$CURRENT_ENV" APP_KEY)" ]]; then
        log_warn "APP_KEY in the backup differs from the current one in ${CURRENT_ENV}."
        log_warn "Users with two-factor authentication will be locked out unless the backup's key is used."
        if [[ $DOCKER -eq 0 ]]; then
            log_warn "Re-run with --new-server to copy the backup's APP_KEY and MAIL_* settings."
        fi
    fi
    return 0
}

confirm() {
    local backup_date
    backup_date=$(date -d "${STAMP:0:8} ${STAMP:9:2}:${STAMP:11:2}:${STAMP:13:2}" '+%A %Y-%m-%d %H:%M:%S')

    echo ""
    echo -e "${BOLD}Restore FD Commander backup${NC}"
    echo "  Backup taken:  ${backup_date}"
    echo "  Database:      ${DB_BACKUP}"
    if [[ $DB_ONLY -eq 0 ]]; then
        echo "  Files:         ${FILES_BACKUP}"
    fi
    if [[ $NEW_SERVER -eq 1 ]]; then
        echo "  Settings:      APP_KEY and MAIL_* from ${ENV_BACKUP}"
    fi
    echo "  Restoring to:  $([[ $DOCKER -eq 1 ]] && echo "Docker stack in ${COMPOSE_DIR}" || echo "${APP_PATH}")"
    echo ""
    echo -e "${YELLOW}${BOLD}This replaces ALL current data with the backup.${NC}"
    echo "Anything logged since ${backup_date} will be lost."
    echo ""

    [[ $ASSUME_YES -eq 1 ]] && return 0

    if ! { : < /dev/tty; } 2>/dev/null; then
        log_error "No terminal to confirm on. Re-run with --yes to restore without a prompt."
        exit 1
    fi

    local reply
    read -r -p 'Type "yes" to continue: ' reply </dev/tty
    if [[ "$reply" != "yes" ]]; then
        log_info "Restore cancelled — nothing was changed."
        exit 1
    fi
    return 0
}

take_pre_restore_backup() {
    log_phase "Backing up current data"

    "$SCRIPT_DIR/backup.sh" "${MODE_ARGS[@]}" --dest "$PRE_RESTORE_DIR" --keep-days 0
    PRE_RESTORE_DB=$(find "$PRE_RESTORE_DIR" -maxdepth 1 -name 'fdc-db-*.sql.gz' | head -1)
    log_info "Current data saved to ${PRE_RESTORE_DIR}"
    return 0
}

# --- Native restore ---

# restore_env_settings: copy APP_KEY and MAIL_* from the backup's env file.
# Leaves DB_PASSWORD and everything else alone — this server has its own.
restore_env_settings() {
    local env_file="$APP_PATH/.env" line key
    while IFS= read -r line; do
        key="${line%%=*}"
        sed -i "/^${key}=/d" "$env_file"
        printf '%s\n' "$line" >> "$env_file"
    done < <(grep -E '^(APP_KEY|MAIL_[A-Z0-9_]+)=' "$ENV_BACKUP")
    chown "fdcommander:${WEB_GROUP}" "$env_file"
    chmod 640 "$env_file"
    return 0
}

restore_native() {
    log_phase "Restoring"

    WEB_GROUP=$(stat -c '%G' "$APP_PATH")

    RESTORE_STARTED=1
    SERVICES_STOPPED=1
    begin_step "Stopping services"
    systemctl stop "${SERVICES[@]}"

    begin_step "Dropping database ${TARGET_DB}"
    mariadb -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`"

    begin_step "Loading database from $(basename "$DB_BACKUP")"
    gunzip -c "$DB_BACKUP" | mariadb

    if [[ $DB_ONLY -eq 0 ]]; then
        begin_step "Restoring storage/app from $(basename "$FILES_BACKUP")"
        tar -xzf "$FILES_BACKUP" -C "$APP_PATH" storage/app
        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH/storage/app"
    fi

    if [[ $NEW_SERVER -eq 1 ]]; then
        begin_step "Copying APP_KEY and MAIL_* settings into .env"
        restore_env_settings
    fi

    cd "$APP_PATH"
    begin_step "Running migrations"
    sudo -u fdcommander php artisan migrate --force

    begin_step "Rebuilding caches"
    sudo -u fdcommander php artisan optimize

    begin_step "Clearing application cache"
    sudo -u fdcommander php artisan cache:clear

    begin_step "Starting services"
    systemctl start "${SERVICES[@]}"
    SERVICES_STOPPED=0
    return 0
}

# --- Docker restore ---

# wait_for_app: the entrypoint migrates and optimizes, then logs this line
# just before it starts supervisord.
wait_for_app() {
    local since="$1" tries=0
    until docker compose logs --since "$since" app 2>/dev/null | grep 'Starting services via supervisord' > /dev/null; do
        tries=$((tries + 1))
        if [[ $tries -ge 60 ]]; then
            log_error "App container did not finish starting — check: docker compose logs app"
            return 1
        fi
        sleep 3
    done
    return 0
}

restore_docker() {
    log_phase "Restoring"

    cd "$COMPOSE_DIR"

    RESTORE_STARTED=1
    if [[ $DB_ONLY -eq 0 ]]; then
        begin_step "Restoring storage/app from $(basename "$FILES_BACKUP")"
        if [[ -n "$(docker compose ps --status running -q app)" ]]; then
            docker compose exec -T app tar -xzf - -C /app/storage < "$FILES_BACKUP"
        else
            # The app is stopped (e.g. after a failed restore): write to the
            # volume through a one-off container instead.
            docker compose run --rm --no-deps -T --entrypoint tar app \
                -xzf - -C /app/storage < "$FILES_BACKUP"
        fi
    fi

    SERVICES_STOPPED=1
    begin_step "Stopping the app container"
    docker compose stop app

    begin_step "Dropping database ${TARGET_DB}"
    # shellcheck disable=SC2016 # expanded by the shell inside the container
    docker compose exec -T mysql sh -c \
        'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`"'

    begin_step "Loading database from $(basename "$DB_BACKUP")"
    # shellcheck disable=SC2016 # expanded by the shell inside the container
    gunzip -c "$DB_BACKUP" | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'

    begin_step "Starting the app container (runs migrations and rebuilds caches)"
    local started_at
    started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    docker compose start app
    wait_for_app "$started_at"

    begin_step "Clearing application cache"
    docker compose exec -T app php artisan cache:clear
    SERVICES_STOPPED=0

    if [[ -f "$ENV_BACKUP" ]]; then
        echo ""
        log_info "The backup's settings are in ${ENV_BACKUP} (not applied)."
        echo "  If this is a new host, or .env was regenerated since the backup, copy"
        echo "  APP_KEY (and any MAIL_* settings) from that file into ${CURRENT_ENV},"
        echo "  then run: docker compose up -d"
        echo "  Keep this host's DB_PASSWORD and DB_ROOT_PASSWORD — the MySQL volume"
        echo "  was created with them."
    fi
    return 0
}

# --- Main ---
main() {
    log_phase "FD Commander Restore — $(date '+%Y-%m-%d %H:%M:%S')"

    preflight
    confirm
    take_pre_restore_backup

    if [[ $DOCKER -eq 1 ]]; then
        restore_docker
    else
        restore_native
    fi
    COMPLETED_STEPS+=("$CURRENT_STEP")

    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}  FD Commander Restore Complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    echo "  Restored backup:     ${STAMP}"
    echo "  Pre-restore backup:  ${PRE_RESTORE_DIR}"
    echo "  Restore log:         ${LOG_FILE}"
    echo ""
}

main
