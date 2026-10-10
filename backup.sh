#!/usr/bin/env bash
set -euo pipefail

# --- Constants ---
SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
LOG_FILE="/var/log/fd-commander-backup.log"
LOCK_FILE="/run/fd-commander-backup.lock"
INSTALLED_SCRIPT="/usr/local/sbin/fdcommander-backup"
SYSTEMD_DIR="/etc/systemd/system"

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
DOCKER=0
COMPOSE_DIR="."
DEST=""
DB_ONLY=0
KEEP_DAYS=7
INSTALL_SCHEDULE=0
REMOVE_SCHEDULE=0

usage() {
    cat <<'USAGE'
Usage: backup.sh [options]

Backs up the FD Commander database, uploaded files (storage/app) and .env.
.env holds APP_KEY, which two-factor secrets are encrypted with, so keep it
with the database backup.

Writes fdc-db-STAMP.sql.gz, fdc-files-STAMP.tar.gz and fdc-env-STAMP to the
destination directory. STAMP is YYYYMMDD-HHMMSS.

Mode:
  --app-path <path>     Native install path (default: /var/www/fd-commander)
  --docker              Back up a Docker Compose install instead
  --compose-dir <dir>   Directory holding docker-compose.yml and .env
                        (default: current directory)

Options:
  --dest <dir>          Where to write backups (default:
                        /var/backups/fd-commander, or ./backups with --docker)
  --db-only             Back up only the database
  --keep-days <n>       Delete fdc-* files in --dest older than n days
                        (default: 7, 0 keeps everything)
  --install-schedule    Install systemd timers (native only): hourly
                        database backups and a nightly full backup. Uses
                        the --app-path, --dest and --keep-days given here.
  --remove-schedule     Remove the systemd timers
  -h, --help            Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-path)         APP_PATH="$2"; shift 2 ;;
        --docker)           DOCKER=1; shift ;;
        --compose-dir)      COMPOSE_DIR="$2"; shift 2 ;;
        --dest)             DEST="$2"; shift 2 ;;
        --db-only)          DB_ONLY=1; shift ;;
        --keep-days)        KEEP_DAYS="$2"; shift 2 ;;
        --install-schedule) INSTALL_SCHEDULE=1; shift ;;
        --remove-schedule)  REMOVE_SCHEDULE=1; shift ;;
        -h|--help)          usage 0 ;;
        *)                  log_error "Unknown option: $1"; usage 1 ;;
    esac
done

# --- Validation ---
if [[ ! "$KEEP_DAYS" =~ ^[0-9]+$ ]]; then
    log_error "--keep-days must be a whole number of days: $KEEP_DAYS"
    exit 1
fi

if [[ $DOCKER -eq 1 ]]; then
    if [[ ! -f "$COMPOSE_DIR/docker-compose.yml" ]]; then
        log_error "No docker-compose.yml in $COMPOSE_DIR — pass --compose-dir"
        exit 1
    fi
    COMPOSE_DIR="$(cd "$COMPOSE_DIR" && pwd)"
    DEST="${DEST:-$COMPOSE_DIR/backups}"
else
    if [[ ! -f "$APP_PATH/artisan" ]]; then
        log_error "No FD Commander install found at $APP_PATH — pass --app-path"
        exit 1
    fi
    APP_PATH="$(cd "$APP_PATH" && pwd)"
    DEST="${DEST:-/var/backups/fd-commander}"
fi
DEST="$(realpath -m "$DEST")"

# env_value: print a key's value from a .env file, without surrounding quotes.
env_value() {
    local file="$1" key="$2" value
    value=$(grep -E "^${key}=" "$file" | tail -1 | cut -d= -f2-)
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    echo "$value"
    return 0
}

# --- Schedule ---
install_schedule() {
    log_phase "Installing backup schedule"

    if [[ $DOCKER -eq 1 ]]; then
        log_error "--install-schedule is for native installs. For Docker, run"
        log_error "backup.sh --docker from cron or a systemd timer on the host."
        exit 1
    fi

    # The timers run as root, so they call a root-owned copy of this script.
    # The copy in APP_PATH is owned by the fdcommander user, and running it
    # as root would let that user run anything as root.
    install -m 755 -o root -g root "$SCRIPT_PATH" "$INSTALLED_SCRIPT"
    log_info "Installed $INSTALLED_SCRIPT"

    local common_args="--app-path \"${APP_PATH}\" --dest \"${DEST}\" --keep-days ${KEEP_DAYS}"

    cat > "$SYSTEMD_DIR/fdcommander-backup-db.service" <<UNITEOF
[Unit]
Description=FD Commander hourly database backup
After=mariadb.service

[Service]
Type=oneshot
ExecStart=${INSTALLED_SCRIPT} ${common_args} --db-only
UNITEOF

    cat > "$SYSTEMD_DIR/fdcommander-backup-db.timer" <<UNITEOF
[Unit]
Description=Run the FD Commander database backup every hour

[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
UNITEOF

    cat > "$SYSTEMD_DIR/fdcommander-backup.service" <<UNITEOF
[Unit]
Description=FD Commander nightly full backup
After=mariadb.service

[Service]
Type=oneshot
ExecStart=${INSTALLED_SCRIPT} ${common_args}
UNITEOF

    # 02:30 rather than on the hour, so it never starts alongside the hourly run.
    cat > "$SYSTEMD_DIR/fdcommander-backup.timer" <<UNITEOF
[Unit]
Description=Run the FD Commander full backup every night

[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true

[Install]
WantedBy=timers.target
UNITEOF

    systemctl daemon-reload
    systemctl enable --now fdcommander-backup-db.timer fdcommander-backup.timer

    log_info "Hourly database backups and nightly full backups (02:30) enabled"
    log_info "Backups go to ${DEST}, kept ${KEEP_DAYS} days"
    log_info "Check them with: systemctl list-timers 'fdcommander-backup*'"
    log_info "After update.sh, the installed copy is refreshed automatically"
    return 0
}

remove_schedule() {
    log_phase "Removing backup schedule"

    systemctl disable --now fdcommander-backup-db.timer fdcommander-backup.timer 2>/dev/null || true
    rm -f "$SYSTEMD_DIR"/fdcommander-backup-db.{service,timer} \
          "$SYSTEMD_DIR"/fdcommander-backup.{service,timer} \
          "$INSTALLED_SCRIPT"
    systemctl daemon-reload

    log_info "Backup timers removed. Existing backups in place were not touched."
    return 0
}

# --- Backup ---

# finish_file: move a completed .part file into place and report its size.
finish_file() {
    local path="$1"
    mv "${path}.part" "$path"
    log_info "Wrote $path ($(du -h "$path" | cut -f1))"
    return 0
}

backup_native() {
    local db
    db=$(env_value "$APP_PATH/.env" DB_DATABASE)
    if [[ -z "$db" ]]; then
        log_error "DB_DATABASE is not set in $APP_PATH/.env"
        exit 1
    fi

    log_info "Dumping database ${db}..."
    mariadb-dump --single-transaction --databases "$db" | gzip > "${DB_FILE}.part"
    finish_file "$DB_FILE"

    [[ $DB_ONLY -eq 1 ]] && return 0

    log_info "Archiving storage/app..."
    # tar exits 1 when a file changes while it is read, which is harmless here.
    tar -czf "${FILES_FILE}.part" -C "$APP_PATH" storage/app || [[ $? -eq 1 ]]
    finish_file "$FILES_FILE"

    cp "$APP_PATH/.env" "${ENV_FILE}.part"
    chmod 600 "${ENV_FILE}.part"
    finish_file "$ENV_FILE"
    return 0
}

backup_docker() {
    cd "$COMPOSE_DIR"

    log_info "Dumping database (a mysqldump warning about the password is expected)..."
    # shellcheck disable=SC2016 # expanded by the shell inside the container
    docker compose exec -T mysql sh -c \
        'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --databases "$MYSQL_DATABASE"' \
        | gzip > "${DB_FILE}.part"
    finish_file "$DB_FILE"

    [[ $DB_ONLY -eq 1 ]] && return 0

    log_info "Archiving storage/app from the app container..."
    if [[ -n "$(docker compose ps --status running -q app)" ]]; then
        docker compose exec -T app tar -czf - -C /app/storage app > "${FILES_FILE}.part" || [[ $? -eq 1 ]]
    else
        # The app is stopped (e.g. after a failed restore): read the volume
        # through a one-off container instead.
        docker compose run --rm --no-deps -T --entrypoint tar app \
            -czf - -C /app/storage app > "${FILES_FILE}.part" || [[ $? -eq 1 ]]
    fi
    finish_file "$FILES_FILE"

    if [[ -f "$COMPOSE_DIR/.env" ]]; then
        cp "$COMPOSE_DIR/.env" "${ENV_FILE}.part"
        chmod 600 "${ENV_FILE}.part"
        finish_file "$ENV_FILE"
    else
        log_warn "No .env in $COMPOSE_DIR — APP_KEY not backed up"
    fi
    return 0
}

prune_old_backups() {
    [[ $KEEP_DAYS -eq 0 ]] && return 0

    local old
    # -mtime +N matches files at least N+1 days old, so subtract one.
    old=$(find "$DEST" -maxdepth 1 -type f -name 'fdc-*' -mtime "+$((KEEP_DAYS - 1))" -print -delete)
    if [[ -n "$old" ]]; then
        log_info "Deleted backups older than ${KEEP_DAYS} days:"
        echo "$old"
    fi
    return 0
}

run_backup() {
    log_phase "FD Commander Backup — $(date '+%Y-%m-%d %H:%M:%S')"

    umask 077
    mkdir -p "$DEST"
    chmod 700 "$DEST"

    # One backup at a time, so the hourly and nightly runs never interleave.
    exec 9> "$LOCK_FILE"
    flock 9

    local stamp
    stamp=$(date +%Y%m%d-%H%M%S)
    while [[ -e "$DEST/fdc-db-${stamp}.sql.gz" ]]; do
        sleep 1
        stamp=$(date +%Y%m%d-%H%M%S)
    done

    DB_FILE="$DEST/fdc-db-${stamp}.sql.gz"
    FILES_FILE="$DEST/fdc-files-${stamp}.tar.gz"
    ENV_FILE="$DEST/fdc-env-${stamp}"
    trap 'rm -f "${DB_FILE}.part" "${FILES_FILE}.part" "${ENV_FILE}.part"' EXIT

    if [[ $DOCKER -eq 1 ]]; then
        backup_docker
    else
        backup_native
    fi

    prune_old_backups

    log_info "Backup complete: ${DEST} (stamp ${stamp})"
    return 0
}

# --- Main ---
main() {
    if [[ $INSTALL_SCHEDULE -eq 1 ]]; then
        install_schedule
    elif [[ $REMOVE_SCHEDULE -eq 1 ]]; then
        remove_schedule
    else
        run_backup
    fi
}

main
