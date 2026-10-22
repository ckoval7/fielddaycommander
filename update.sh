#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

set -euo pipefail

# --- Constants ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/fd-commander-update.log"
NODE_MAJOR="22"

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

# Files this script creates are not world-writable; secrets (.env, the log)
# get their own stricter modes.
umask 022

# --- Logging ---
# All output already goes through the tee to $LOG_FILE set up below, so these
# only print; teeing here as well wrote every line to the log twice.
log_info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }
log_phase()   { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}"; }

# The log can hold secrets and hostnames, so keep it readable by root only.
(umask 077 && touch "$LOG_FILE")
chmod 600 "$LOG_FILE"

# Redirect all stdout/stderr to log file as well. A re-exec of the updated
# script (see reexec_if_script_changed) already writes through this tee.
if [[ -z "${FDC_UPDATE_REEXEC:-}" ]]; then
    exec > >(tee -a "$LOG_FILE") 2>&1
fi

# Kept for re-running the updated copy of this script after the pull.
ORIG_ARGS=("$@")
SELF_SHA=$(sha256sum < "${BASH_SOURCE[0]}" | cut -d' ' -f1)

# --- Defaults ---
APP_PATH="/var/www/fd-commander"
BRANCH="main"
TAG=""
FORCE=0
NO_REDIS=0
WITH_REDIS=0
SKIP_BACKUP=0

usage() {
    cat <<'USAGE'
Usage: update.sh [options]

Updates an existing Field Day Commander deployment with the latest code.

Options:
  --app-path <path>     Deploy path (default: /var/www/fd-commander)
  --branch <branch>     Git branch (default: main)
  --tag <tag>           Deploy a specific release tag (e.g. v26.05.1).
                        Overrides --branch and pins the deploy in detached HEAD.
  --force               Run full update pipeline even if already up to date
  --no-redis            Skip Redis install and .env driver migration
                        (keep existing cache/session/queue drivers)
  --with-redis          Install Redis and migrate drivers without prompting
                        (use in non-interactive automation)
  --skip-backup         Don't take a backup before updating (one is taken by
                        default, since migrations change the database)
  -h, --help            Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-path)  APP_PATH="$2"; shift 2 ;;
        --branch)    BRANCH="$2"; shift 2 ;;
        --tag)       TAG="$2"; shift 2 ;;
        --force)     FORCE=1; shift ;;
        --no-redis)  NO_REDIS=1; shift ;;
        --with-redis) WITH_REDIS=1; shift ;;
        --skip-backup) SKIP_BACKUP=1; shift ;;
        -h|--help)   usage 0 ;;
        *)           log_error "Unknown option: $1"; usage 1 ;;
    esac
done

# --- Validation ---
if [[ ! -d "$APP_PATH" ]]; then
    log_error "App path does not exist: $APP_PATH"
    log_error "Has Field Day Commander been deployed? Run deploy.sh first."
    exit 1
fi

if [[ ! -f "$APP_PATH/artisan" ]]; then
    log_error "No Laravel application found at $APP_PATH"
    exit 1
fi

# The web group is the app user's own group, the one deploy.sh created it
# with. (The install directory is root-owned, so its group says nothing.)
if ! WEB_GROUP=$(id -gn fdcommander 2>/dev/null); then
    log_error "User fdcommander not found. Has Field Day Commander been deployed with deploy.sh?"
    exit 1
fi

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

# --- Ownership (same as deploy.sh) ---
# The code (including deploy.sh, update.sh, backup.sh and restore.sh, which
# run as root) stays root-owned, so the fdcommander user cannot change what
# root later runs. fdcommander may write only the runtime directories and the
# build output that composer and npm, run as fdcommander, produce. Nothing
# run as root loads code from those build directories.
APP_RUNTIME_DIRS=(storage bootstrap/cache)
APP_BUILD_DIRS=(vendor node_modules public/build .composer .npm)

apply_app_permissions() {
    local dir
    # Secrets first, and kept out of the recursive pass below, so .env is never
    # world-readable (or unreadable by the app) even for a moment. Other .env.*
    # copies (old backups and the like) are root-only.
    if [[ -f "$APP_PATH/.env" ]]; then
        chown "root:${WEB_GROUP}" "$APP_PATH/.env"
        chmod 640 "$APP_PATH/.env"
    fi
    find "$APP_PATH" -maxdepth 1 -type f -name '.env.*' ! -name '.env.example' \
        -exec chown root:root {} + -exec chmod 600 {} +
    # Code: root-owned and read-only to everyone else. storage/ is skipped so
    # logs and sessions are not made world-readable; it is handled below.
    # -h and ! -type l leave symlink targets (public/storage) alone.
    find "$APP_PATH" \( -path "$APP_PATH/storage" -o -path "$APP_PATH/.env" -o \( -path "$APP_PATH/.env.*" ! -path "$APP_PATH/.env.example" \) \) -prune \
        -o -exec chown -h root:root {} +
    find "$APP_PATH" \( -path "$APP_PATH/storage" -o -path "$APP_PATH/.env" -o \( -path "$APP_PATH/.env.*" ! -path "$APP_PATH/.env.example" \) \) -prune \
        -o ! -type l -exec chmod u+rwX,go+rX,go-w {} +
    for dir in "${APP_BUILD_DIRS[@]}" "${APP_RUNTIME_DIRS[@]}"; do
        mkdir -p "$APP_PATH/$dir"
        chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH/$dir"
    done
    # Directories group-writable; files read-write but never executable (an
    # older deploy's chmod -R 775 marked tracked files like .gitignore
    # executable, which git then reports as changed).
    for dir in "${APP_RUNTIME_DIRS[@]}"; do
        find "$APP_PATH/$dir" -type d -exec chmod ug+rwx,o-w {} +
        find "$APP_PATH/$dir" -type f -exec chmod ug+rw,a-x,o-w {} +
    done
    return 0
}

# An earlier update.sh or restore.sh of this version could leave .env
# root:root, so the app could not read it. Put it back before anything else
# and run the full update so caches built without it are rebuilt.
repair_env_access() {
    local env_file="$APP_PATH/.env"
    [[ -f "$env_file" ]] || return 0
    if [[ "$(stat -c '%G' "$env_file")" != "$WEB_GROUP" ]] || ! sudo -u fdcommander test -r "$env_file"; then
        chown "root:${WEB_GROUP}" "$env_file"
        chmod 640 "$env_file"
        log_warn "Fixed ${env_file}: the app could not read it. Running the full update to rebuild caches."
        FORCE=1
    fi
    return 0
}

# Git as root against the root-owned checkout. A checkout an older deploy
# left owned by fdcommander is used as that user until pull_updates takes it
# over, so root never runs hooks or config the app user could have written.
app_git() {
    if [[ "$(stat -c '%U' "$APP_PATH/.git")" == "root" ]]; then
        git -C "$APP_PATH" "$@"
    else
        sudo -u fdcommander git -C "$APP_PATH" "$@"
    fi
}

# --- Fetch and check for updates ---
DEPLOYED_REV_FILE="$APP_PATH/.deployed-revision"
NPM_LOCK_HASH_FILE="$APP_PATH/.npm-lock-hash"

fetch_updates() {
    log_phase "Checking for updates"

    if [[ -n "$TAG" ]]; then
        log_info "Pinning to tag: $TAG"
    else
        log_info "Following branch: $BRANCH"
    fi

    if [[ "$DEPLOY_METHOD" == "git" ]]; then
        cd "$APP_PATH"

        if [[ -n "$TAG" ]]; then
            app_git fetch origin --tags --prune --prune-tags
            REMOTE=$(app_git rev-list -n 1 "$TAG" 2>/dev/null || true)
            if [[ -z "$REMOTE" ]]; then
                log_error "Tag $TAG not found after fetch from origin"
                exit 1
            fi
        else
            app_git fetch origin "$BRANCH"
            REMOTE=$(app_git rev-parse "origin/$BRANCH")
        fi

        LOCAL=$(app_git rev-parse HEAD)

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

        if [[ -n "$TAG" ]]; then
            git fetch origin --tags --prune --prune-tags
            SOURCE_REV=$(git rev-list -n 1 "$TAG" 2>/dev/null || true)
            if [[ -z "$SOURCE_REV" ]]; then
                log_error "Tag $TAG not found after fetch from origin"
                exit 1
            fi
        else
            git fetch origin "$BRANCH"

            # Pull source to latest if behind remote
            LOCAL=$(git rev-parse HEAD)
            REMOTE=$(git rev-parse "origin/$BRANCH")
            if [[ "$LOCAL" != "$REMOTE" ]]; then
                log_info "Source repo is behind remote, pulling..."
                git pull origin "$BRANCH"
            fi

            SOURCE_REV=$(git rev-parse HEAD)
        fi

        # Compare source HEAD against last deployed revision
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
        if [[ -n "$TAG" ]]; then
            app_git checkout --detach "$TAG"
        else
            # If a prior deploy left us detached on a tag, reattach to the branch first.
            app_git checkout "$BRANCH"
            app_git pull --ff-only origin "$BRANCH"
        fi
        apply_app_permissions

        # The root-run scripts must match the commit: discard any local edits
        # (e.g. made while an older deploy let fdcommander write them).
        local script
        for script in deploy.sh update.sh backup.sh restore.sh; do
            if app_git ls-files --error-unmatch "$script" &>/dev/null \
                && ! app_git diff --quiet HEAD -- "$script"; then
                log_warn "Discarding local changes to $script"
                app_git checkout HEAD -- "$script"
            fi
        done
    else
        cd "$SCRIPT_DIR"
        if [[ -n "$TAG" ]]; then
            log_info "Checking out source repo at tag $TAG (detached)"
            git checkout --detach "$TAG"
        fi

        log_info "Syncing files to $APP_PATH..."
        rsync -a --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='vendor' \
            --exclude='.env' \
            --exclude='.deployed-revision' \
            --exclude='.npm-lock-hash' \
            --exclude='public/storage' \
            --exclude='storage/app' \
            --exclude='storage/logs' \
            --exclude='storage/framework/sessions' \
            --exclude='storage/framework/cache/data' \
            "$SCRIPT_DIR/" "$APP_PATH/"

        # Record the deployed revision
        cd "$SCRIPT_DIR"
        git rev-parse HEAD > "$DEPLOYED_REV_FILE"

        apply_app_permissions
    fi

    # Operator scripts run as root; older tags may not ship them.
    for script in deploy.sh update.sh backup.sh restore.sh; do
        if [[ -f "$APP_PATH/$script" ]]; then
            chmod 755 "$APP_PATH/$script"
        fi
    done

    # Refresh the root-owned copy the backup timers run, if they are installed.
    # (update.sh never installs the schedule itself — see backup.sh --install-schedule.)
    # The source is the root-owned checkout (git) or the operator's source
    # tree (rsync), never a file the app user can write.
    local backup_source="$APP_PATH/backup.sh"
    [[ "$DEPLOY_METHOD" == "rsync" ]] && backup_source="$SCRIPT_DIR/backup.sh"
    if [[ -f /usr/local/sbin/fdcommander-backup && -f "$backup_source" ]]; then
        install -m 755 -o root -g root "$backup_source" /usr/local/sbin/fdcommander-backup
        log_info "Refreshed /usr/local/sbin/fdcommander-backup"
    fi

    # Ensure storage symlink exists (what storage:link creates; public/ is root-owned)
    if [[ ! -L "$APP_PATH/public/storage" ]]; then
        ln -s "$APP_PATH/storage/app/public" "$APP_PATH/public/storage"
        log_info "Recreated storage symlink"
    fi

    log_info "Code updated"
}

# Re-run the freshly pulled update.sh when it differs from the one running, so
# a single run applies the new version's steps (permissions, unit hardening,
# settings) instead of the next one. FDC_UPDATE_REEXEC stops a loop. --force
# makes the new copy carry on although the code is now up to date. Nothing has
# been stopped yet, so no maintenance mode or trap carries over.
reexec_if_script_changed() {
    [[ -z "${FDC_UPDATE_REEXEC:-}" ]] || return 0
    local new_script="$APP_PATH/update.sh"
    [[ "$DEPLOY_METHOD" == "rsync" ]] && new_script="$SCRIPT_DIR/update.sh"
    [[ -f "$new_script" ]] || return 0

    local new_sha
    new_sha=$(sha256sum < "$new_script" | cut -d' ' -f1)
    [[ "$new_sha" != "$SELF_SHA" ]] || return 0

    # A copy without this re-exec support is an older release (e.g. --tag to
    # an earlier version). It cannot run against a root-owned checkout, so
    # keep going with the running script.
    if ! grep -q 'FDC_UPDATE_REEXEC' "$new_script"; then
        log_warn "The update.sh in this version is older than the one running; continuing with the running one"
        return 0
    fi

    local args=("${ORIG_ARGS[@]}") arg has_force=0
    for arg in "${args[@]}"; do
        [[ "$arg" == "--force" ]] && has_force=1
    done
    [[ $has_force -eq 1 ]] || args+=(--force)

    log_info "update.sh changed in this update; continuing with the new version"
    export FDC_UPDATE_REEXEC=1
    exec bash "$new_script" "${args[@]}"
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

# Rename the product in unit descriptions written by older installs, so
# systemctl status shows the full name. Idempotent.
rename_unit_descriptions() {
    local changed=0 unit_file
    for unit_file in /etc/systemd/system/fdcommander*.service /etc/systemd/system/fdcommander*.timer; do
        [[ -f "$unit_file" ]] || continue
        if grep -q 'FD Commander' "$unit_file"; then
            sed -i 's/FD Commander/Field Day Commander/g' "$unit_file"
            changed=1
        fi
    done

    if [[ $changed -eq 1 ]]; then
        log_info "Renamed services to Field Day Commander"
        systemctl daemon-reload
    fi
    return 0
}

# Convert the scheduler from a oneshot unit + timer (older installs) to a
# long-running schedule:work service. A oneshot unit's cgroup is killed when
# schedule:run exits, which also kills any external-logger listener that
# external-logger:monitor just restarted. Idempotent: no-ops once converted.
migrate_scheduler_unit() {
    local unit_file="/etc/systemd/system/fdcommander-scheduler.service"
    local timer_file="/etc/systemd/system/fdcommander-scheduler.timer"
    if [[ ! -f "$unit_file" ]]; then
        log_warn "Scheduler unit not found at ${unit_file} — skipping scheduler migration"
        return 0
    fi

    if ! grep -q '^Type=oneshot' "$unit_file"; then
        return 0
    fi

    log_info "Converting scheduler from a oneshot timer to a long-running service"
    if [[ -f "$timer_file" ]]; then
        systemctl disable --now fdcommander-scheduler.timer || true
        rm -f "$timer_file"
    fi

    sed -i -E -e '/^Type=oneshot$/d' -e 's/schedule:run --no-interaction/schedule:work --no-interaction/' "$unit_file"
    cat >> "$unit_file" <<'SCHEDEOF'
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SCHEDEOF

    systemctl daemon-reload
    systemctl enable fdcommander-scheduler.service
    return 0
}

# Download and verify the FrankenPHP version deploy.sh pins if this server
# runs a different one, so a version bump only needs changing in deploy.sh.
# Runs before maintenance mode; install_staged_frankenphp swaps it in during
# the restart. A failed download or check keeps the current binary rather than
# aborting the update.
FRANKENPHP_STAGED=""

stage_frankenphp_update() {
    local binary="/usr/local/bin/frankenphp"
    local desired current
    desired=$(grep -m1 -E '^FRANKENPHP_VERSION=' "$APP_PATH/deploy.sh" 2>/dev/null | cut -d'"' -f2 || true)
    if [[ -z "$desired" ]]; then
        log_warn "Could not read FRANKENPHP_VERSION from $APP_PATH/deploy.sh — skipping FrankenPHP upgrade"
        return 0
    fi
    if [[ ! -x "$binary" ]]; then
        log_warn "FrankenPHP not found at ${binary} — skipping upgrade"
        return 0
    fi

    current=$("$binary" version 2>/dev/null | awk '{print $2}' | sed 's/^v//' || true)

    local arch sha_var
    case "$(uname -m)" in
        x86_64)  arch="linux-x86_64-gnu";  sha_var="FRANKENPHP_SHA256_X86_64" ;;
        aarch64) arch="linux-aarch64-gnu"; sha_var="FRANKENPHP_SHA256_AARCH64" ;;
        *)       log_warn "Unsupported architecture $(uname -m) — keeping FrankenPHP ${current}"
                 return 0 ;;
    esac

    # deploy.sh pins the binary's SHA-256 next to the version.
    local expected_sha
    expected_sha=$(grep -m1 -E "^${sha_var}=" "$APP_PATH/deploy.sh" 2>/dev/null | cut -d'"' -f2 || true)
    if [[ ! "$expected_sha" =~ ^[0-9a-f]{64}$ ]]; then
        log_warn "No ${sha_var} pinned in $APP_PATH/deploy.sh — keeping FrankenPHP ${current}"
        return 0
    fi

    # Compare the binary itself, not just the version: 1.13.2 installs got the
    # musl build, which has to be swapped for the -gnu build of the same version.
    if echo "${expected_sha}  ${binary}" | sha256sum --check --quiet --status; then
        log_info "FrankenPHP already at ${desired}"
        return 0
    fi

    local url="https://github.com/php/frankenphp/releases/download/v${desired}/frankenphp-${arch}"
    local tmp="${binary}.new"
    log_info "Downloading FrankenPHP ${desired} (installed: ${current:-unknown})..."
    if ! curl -fSL --proto '=https' --tlsv1.2 -o "$tmp" "$url"; then
        rm -f "$tmp"
        log_warn "Download failed — keeping FrankenPHP ${current}"
        return 0
    fi
    if ! echo "${expected_sha}  ${tmp}" | sha256sum --check --quiet --status; then
        rm -f "$tmp"
        log_warn "Downloaded FrankenPHP does not match the pinned SHA-256 — keeping FrankenPHP ${current}"
        return 0
    fi
    chmod 755 "$tmp"
    if ! "$tmp" version &>/dev/null || ! "$tmp" php-cli -r 'hash("sha256", "x");' &>/dev/null; then
        rm -f "$tmp"
        log_warn "Downloaded FrankenPHP binary did not run on this server — keeping FrankenPHP ${current}"
        return 0
    fi
    setcap cap_net_bind_service=+ep "$tmp" || log_warn "setcap failed — FrankenPHP relies on the unit's capabilities to bind ports below 1024"
    FRANKENPHP_STAGED="$tmp"
    log_info "FrankenPHP ${desired} downloaded and verified; it is installed during the restart"
    return 0
}

# Older installs have a php.ini from before deploy.sh turned off PHP's native
# session upload progress, which Laravel never uses and which was one of the
# ways in for CVE-2026-109618. Add the setting unless the admin has set it.
harden_php_ini() {
    local ini_file="/etc/frankenphp/php.ini"
    [[ -f "$ini_file" ]] || return 0
    if grep -qE '^[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=' "$ini_file"; then
        return 0
    fi
    printf '\nsession.upload_progress.enabled = Off\n' >> "$ini_file"
    log_info "Turned off session.upload_progress in ${ini_file}"
    return 0
}

# Swap in the binary stage_frankenphp_update verified. A rename over the old
# binary, so the running server is never left with a half-written file.
install_staged_frankenphp() {
    [[ -n "$FRANKENPHP_STAGED" && -f "$FRANKENPHP_STAGED" ]] || return 0
    local binary="/usr/local/bin/frankenphp"
    mv -f "$FRANKENPHP_STAGED" "$binary"
    FRANKENPHP_STAGED=""
    if command -v restorecon &>/dev/null; then
        restorecon "$binary" 2>/dev/null || true
    fi
    log_info "FrankenPHP $("$binary" version | awk '{print $2}') installed"
    return 0
}

# Start Octane with the PHP CLI instead of `frankenphp php-cli` (older
# installs). Since FrankenPHP 1.13, php-cli puts the script path in $argv[1],
# which artisan misreads as the command name, so Octane never starts.
# Idempotent: no-ops once converted.
migrate_octane_unit() {
    local unit_file="/etc/systemd/system/fdcommander.service"
    if [[ ! -f "$unit_file" ]] || ! grep -q 'frankenphp php-cli artisan octane:frankenphp' "$unit_file"; then
        return 0
    fi

    log_info "Switching the Octane unit to start through the PHP CLI"
    sed -i -E 's|^ExecStart=[^ ]*frankenphp php-cli artisan octane:frankenphp|ExecStart=/usr/bin/php artisan octane:frankenphp|' "$unit_file"
    systemctl daemon-reload
    return 0
}

# Poll an idle queue every second instead of every three (older installs).
# Live-update broadcasts go out through the queue worker, so the sleep is the
# worst-case delay before open pages see a new contact. Idempotent: no-ops once
# converted.
migrate_queue_unit() {
    local unit_file="/etc/systemd/system/fdcommander-queue.service"
    if [[ ! -f "$unit_file" ]] || ! grep -q 'queue:work --sleep=3 ' "$unit_file"; then
        return 0
    fi

    log_info "Lowering the queue worker's idle sleep to 1 second"
    sed -i -E 's|queue:work --sleep=3 |queue:work --sleep=1 |' "$unit_file"
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

# Print the last value set for a key in the app's .env, without quotes.
get_env() {
    local key="$1"
    grep -E "^${key}=" "$APP_PATH/.env" | tail -1 | cut -d= -f2- | tr -d '"' || true
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
# Field Day Commander Redis tuning — durable queue + heavy RAM cache.
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
        echo "# Field Day Commander overrides" >> "$conf_file"
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
        chown "root:${WEB_GROUP}" "$env_file"
        chmod 640 "$env_file"
        log_info ".env migrated to Redis drivers"
    fi
}

# --- NodeSource repos (same as deploy.sh) ---
# NodeSource's documented manual setup (signed repo + keyring) rather than
# piping its setup script into a root shell; apt/dnf verify every package.
add_nodesource_repo_debian() {
    log_info "Adding NodeSource repo for Node ${NODE_MAJOR}..."
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL --proto '=https' --tlsv1.2 https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    chmod 644 /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -y
    return 0
}

add_nodesource_repo_rhel() {
    log_info "Adding NodeSource repo for Node ${NODE_MAJOR}..."
    dnf module disable -y nodejs &>/dev/null || true
    cat > /etc/yum.repos.d/nodesource-nodejs.repo <<NODEREPOEOF
[nodesource-nodejs]
name=Node.js Packages for $(uname -m)
baseurl=https://rpm.nodesource.com/pub_${NODE_MAJOR}.x/nodistro/nodejs/$(uname -m)
priority=9
enabled=1
gpgcheck=1
gpgkey=https://rpm.nodesource.com/gpgkey/ns-operations-public.key
module_hotfixes=1
NODEREPOEOF
    return 0
}

# --- Upgrade Node.js on installs older than NODE_MAJOR ---
# The asset build (Vite) needs a current Node LTS. deploy.sh installs
# NODE_MAJOR on new hosts; this brings existing hosts up to the same version.
ensure_node_version() {
    local installed_major=0
    if command -v node &>/dev/null; then
        installed_major=$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)
    fi

    if (( installed_major >= NODE_MAJOR )); then
        return 0
    fi

    log_phase "Upgrading Node.js to ${NODE_MAJOR}"

    if [[ -z "$DISTRO_FAMILY" ]]; then
        log_error "Node.js ${NODE_MAJOR}+ is required (found ${installed_major}). Install it manually and re-run."
        exit 1
    fi

    if [[ "$PKG_MANAGER" == "apt" ]]; then
        add_nodesource_repo_debian
        apt-get install -y nodejs
    else
        add_nodesource_repo_rhel
        dnf install -y nodejs
    fi

    log_info "Node.js $(node --version) installed"

    # Force a clean npm ci so native modules match the new runtime.
    rm -f "$NPM_LOCK_HASH_FILE"
}

# --- Install dependencies ---
install_dependencies() {
    log_phase "Installing dependencies"

    cd "$APP_PATH"

    log_info "Installing Composer dependencies..."
    sudo -u fdcommander COMPOSER_HOME="$APP_PATH/.composer" \
        /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

    install_node_dependencies

    log_info "Building assets..."
    sudo -u fdcommander HOME="$APP_PATH" NO_UPDATE_NOTIFIER=1 npm run build
}

# Run `npm ci` only when the dependency set actually changed.
#
# `npm ci` deletes node_modules and reinstalls every package from scratch,
# which takes minutes even on a fast host and is almost entirely network
# wait. Most updates touch no dependencies at all, so hash package-lock.json
# and skip the reinstall when it matches the last successful run.
#
# The hash is written only after a successful install, so an interrupted or
# failed run reinstalls next time rather than being wrongly skipped.
install_node_dependencies() {
    local lock_file="$APP_PATH/package-lock.json"

    if [[ ! -f "$lock_file" ]]; then
        log_warn "No package-lock.json found — running npm install"
        sudo -u fdcommander HOME="$APP_PATH" NO_UPDATE_NOTIFIER=1 npm install --ignore-scripts --cache "$APP_PATH/.npm"
        return 0
    fi

    local current_hash previous_hash=""
    current_hash=$(sha256sum "$lock_file" | cut -d' ' -f1)
    [[ -f "$NPM_LOCK_HASH_FILE" ]] && previous_hash=$(cat "$NPM_LOCK_HASH_FILE")

    # node_modules is excluded from rsync and can be absent on a first update
    # or after a manual clean (apply_app_permissions recreates it empty), so
    # verify npm's own install marker before trusting the hash.
    if [[ "$current_hash" == "$previous_hash" && -f "$APP_PATH/node_modules/.package-lock.json" ]]; then
        log_info "Node dependencies unchanged — skipping npm ci"
        return 0
    fi

    if [[ -n "$previous_hash" && "$current_hash" != "$previous_hash" ]]; then
        log_info "package-lock.json changed — reinstalling Node dependencies..."
    else
        log_info "Installing Node dependencies..."
    fi

    sudo -u fdcommander HOME="$APP_PATH" NO_UPDATE_NOTIFIER=1 npm ci --ignore-scripts --cache "$APP_PATH/.npm"

    # Root-owned like the rest of the checkout, so the app user cannot mark
    # a tampered node_modules as current.
    echo "$current_hash" > "$NPM_LOCK_HASH_FILE"
    chown root:root "$NPM_LOCK_HASH_FILE"
}

# --- Run migrations ---
run_migrations() {
    log_phase "Running migrations"

    cd "$APP_PATH"
    sudo -u fdcommander php artisan migrate --force

    # In demo mode the default connection is 'demo' (demo_base), so the run
    # above never reaches the main 'mysql' connection where the analytics
    # tables (demo_sessions, demo_events) live. Migrate it explicitly, the
    # same way deploy.sh does — without this, migrations that touch those
    # tables are silently skipped and provisioning 500s on the missing column.
    if grep -qE '^DEMO_MODE=(true|1)$' "$APP_PATH/.env" 2>/dev/null; then
        log_info "Demo mode detected — migrating main database (analytics tables)..."
        sudo -u fdcommander php artisan migrate --database=mysql --force
    fi

    log_info "Migrations complete"
}

# --- Rebuild caches ---
rebuild_caches() {
    log_phase "Rebuilding caches"

    cd "$APP_PATH"
    sudo -u fdcommander php artisan optimize
    log_info "Caches rebuilt"
}

# --- Settings added since older deploys (same values deploy.sh writes) ---
migrate_env_settings() {
    local env_file="$APP_PATH/.env"
    [[ -f "$env_file" ]] || return 0
    local changed=false app_url site_address

    # Secure session cookie when the site is served over HTTPS.
    app_url=$(get_env APP_URL)
    if [[ "$app_url" == https://* ]] && ! grep -qE '^SESSION_SECURE_COOKIE=' "$env_file"; then
        set_env "SESSION_SECURE_COOKIE" "true"
        log_info "Enabled SESSION_SECURE_COOKIE (APP_URL is HTTPS)"
        changed=true
    fi

    # Daily log rotation. LOG_DAILY_DAYS marks the one-time switch, so an
    # explicit LOG_STACK choice made afterwards is kept.
    if ! grep -qE '^LOG_DAILY_DAYS=' "$env_file"; then
        if ! grep -qE '^LOG_STACK=' "$env_file" || grep -qE '^LOG_STACK="?single"?$' "$env_file"; then
            set_env "LOG_STACK" "daily"
            log_info "Switched logging to daily files (LOG_STACK=daily)"
        fi
        set_env "LOG_DAILY_DAYS" "14"
        changed=true
    fi

    if $changed; then
        chown "root:${WEB_GROUP}" "$env_file"
        chmod 640 "$env_file"
    fi

    # TLS settings for HTTPS sites deployed before deploy.sh wrote them.
    # Older deploys recorded --ssl-cert/--ssl-key as SSL_CERT/SSL_KEY in .env
    # without Caddy using them; the ACME email was not recorded. Caddy has been
    # using automatic HTTPS on those sites, so that stays the fallback.
    site_address=$(grep -E '^CADDY_SITE_ADDRESS=' "$env_file" | tail -1 | cut -d= -f2- | tr -d '"' || true)
    if [[ "$site_address" == https://* && ! -f "${CADDY_SNIPPET_DIR}/tls.caddy" ]]; then
        local cert key
        cert=$(grep -E '^SSL_CERT=' "$env_file" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        key=$(grep -E '^SSL_KEY=' "$env_file" | tail -1 | cut -d= -f2- | tr -d '"' || true)
        if [[ -z "$cert" || -z "$key" ]] || ! tls_files_usable "$cert" "$key"; then
            cert=""
            key=""
        fi
        write_caddy_tls_snippet "$cert" "$key" ""
        log_info "Wrote Caddy TLS settings to ${CADDY_SNIPPET_DIR}/tls.caddy"
    fi

    migrate_hsts_from_caddy
    note_trusted_hosts
    note_trusted_proxies
    return 0
}

# path_in_private_tmp <path>: same as deploy.sh. Whether the path (after
# resolving symlinks) is under /tmp or /var/tmp, which the services, running
# with PrivateTmp=true, cannot see.
path_in_private_tmp() {
    local resolved
    resolved=$(realpath -m -- "$1" 2>/dev/null || echo "$1")
    [[ "$resolved" == /tmp/* || "$resolved" == /var/tmp/* ]]
}

# tls_files_usable <cert> <key>: whether Caddy (running as fdcommander) can
# read both files and the certificate has not expired. Warns and returns 1
# otherwise, so the caller keeps automatic HTTPS instead of breaking the site.
tls_files_usable() {
    local cert="$1" key="$2" file
    if [[ "${cert}${key}" == *\"* ]]; then
        log_warn "SSL_CERT/SSL_KEY in .env contain a double quote; keeping automatic HTTPS"
        return 1
    fi
    for file in "$cert" "$key"; do
        if path_in_private_tmp "$file"; then
            log_warn "${file} (SSL_CERT/SSL_KEY in .env) is under /tmp or /var/tmp, which the"
            log_warn "Field Day Commander services cannot see (PrivateTmp), so Caddy keeps using automatic"
            log_warn "HTTPS. Move the certificate and key somewhere permanent (e.g. /etc/ssl/fd-commander/),"
            log_warn "update SSL_CERT/SSL_KEY, delete ${CADDY_SNIPPET_DIR}/tls.caddy and run update.sh --force."
            return 1
        fi
        if ! sudo -u fdcommander test -r "$file"; then
            log_warn "fdcommander cannot read ${file} (SSL_CERT/SSL_KEY in .env), so Caddy keeps"
            log_warn "using automatic HTTPS. To use that certificate, make both files readable by"
            log_warn "group ${WEB_GROUP} (chgrp ${WEB_GROUP} <file> && chmod 640 <file>), delete"
            log_warn "${CADDY_SNIPPET_DIR}/tls.caddy and run update.sh --force."
            return 1
        fi
    done
    if command -v openssl &>/dev/null && ! openssl x509 -checkend 0 -noout -in "$cert" &>/dev/null; then
        log_warn "Certificate ${cert} (SSL_CERT in .env) has expired or is not a PEM certificate,"
        log_warn "so Caddy keeps using automatic HTTPS. To use a renewed certificate, delete"
        log_warn "${CADDY_SNIPPET_DIR}/tls.caddy and run update.sh --force."
        return 1
    fi
    return 0
}

# HSTS moved from the Caddy snippet to the app (SECURITY_HSTS_MAX_AGE). A
# snippet an earlier update.sh/deploy.sh wrote with the header loses it; sites
# on automatic Let's Encrypt certificates (an ACME email in the snippet) keep
# HSTS through the app setting. Custom certificates may be self-signed, so
# those are left off.
migrate_hsts_from_caddy() {
    local snippet="${CADDY_SNIPPET_DIR}/tls.caddy"
    local env_file="$APP_PATH/.env"
    [[ -f "$snippet" ]] && grep -q 'Strict-Transport-Security' "$snippet" || return 0

    sed -i '/Strict-Transport-Security/d' "$snippet"
    if grep -qE '^tls [^"]+@' "$snippet" && ! grep -qE '^SECURITY_HSTS_MAX_AGE=' "$env_file"; then
        set_env "SECURITY_HSTS_MAX_AGE" "31536000"
        chown "root:${WEB_GROUP}" "$env_file"
        chmod 640 "$env_file"
        log_info "HSTS now comes from the app (SECURITY_HSTS_MAX_AGE=31536000)"
    else
        log_info "Removed HSTS from ${snippet}; set SECURITY_HSTS_MAX_AGE in .env to send it"
    fi
    return 0
}

# The app now only answers requests for the APP_URL host, its subdomains and
# local-network addresses. Tell the admin once, and record an empty
# TRUSTED_HOSTS (the same as unset) so the note isn't repeated.
note_trusted_hosts() {
    local env_file="$APP_PATH/.env" app_host
    grep -qE '^TRUSTED_HOSTS=' "$env_file" && return 0

    app_host=$(get_env APP_URL | sed -E 's#^[a-z]+://##; s#[:/].*$##' || true)
    set_env "TRUSTED_HOSTS" ""
    chown "root:${WEB_GROUP}" "$env_file"
    chmod 640 "$env_file"
    # Not enforced while APP_URL is localhost.
    [[ -n "$app_host" && "$app_host" != "localhost" ]] || return 0
    log_warn "Field Day Commander now only answers for ${app_host}, private IP addresses"
    log_warn "and .local names. If people reach it by another name or a public IP, add it to"
    log_warn "TRUSTED_HOSTS in ${env_file} (comma-separated), then run: sudo -u fdcommander php artisan optimize"
    return 0
}

# Forwarded headers (X-Forwarded-For) are now believed only from this machine,
# since FrankenPHP faces browsers directly. A TRUSTED_PROXIES the admin set is
# kept. Otherwise tell them once, and record a comment so the note isn't
# repeated (a commented line also means "not set", like .env.example's).
note_trusted_proxies() {
    local env_file="$APP_PATH/.env"
    grep -qE '^#? *TRUSTED_PROXIES=' "$env_file" && return 0

    printf '%s\n' "# TRUSTED_PROXIES=127.0.0.1,::1 (the default; list your reverse proxy's IP if one sits in front)" >> "$env_file"
    log_warn "Field Day Commander now believes X-Forwarded-For only from this machine (127.0.0.1, ::1)."
    log_warn "If a reverse proxy or load balancer sits in front of it, set TRUSTED_PROXIES in"
    log_warn "${env_file} to its IP or CIDR (comma-separated), then run: sudo -u fdcommander php artisan optimize"
    return 0
}

# Root-owned Caddyfile snippets imported into the site block (see Caddyfile).
CADDY_SNIPPET_DIR="/etc/frankenphp/caddy.d"

# write_caddy_tls_snippet <cert> <key> <email>: same as deploy.sh (HSTS comes
# from the app, see SECURITY_HSTS_MAX_AGE).
write_caddy_tls_snippet() {
    local cert="$1" key="$2" email="$3"
    local snippet="${CADDY_SNIPPET_DIR}/tls.caddy"
    install -d -m 0755 "$CADDY_SNIPPET_DIR"
    {
        echo "# Written by deploy.sh/update.sh. Imported into the Field Day Commander site block."
        if [[ -n "$cert" ]]; then
            echo "tls \"${cert}\" \"${key}\""
        elif [[ -n "$email" ]]; then
            echo "tls ${email}"
        fi
    } > "$snippet"
    chmod 644 "$snippet"
    return 0
}

# write_unit_hardening: same as deploy.sh. Sandboxes every Field Day Commander unit
# with a drop-in; the services may write only storage/ and bootstrap/cache
# (plus Caddy's certificate store for the web server).
write_unit_hardening() {
    local protect_home="true" unit dir extra_paths
    # An install or certificate under /home or /root must stay readable.
    if [[ "$APP_PATH" == /home/* || "$APP_PATH" == /root/* ]] \
        || grep -sqE '"/(home|root)/' "${CADDY_SNIPPET_DIR}"/*.caddy; then
        protect_home="read-only"
    fi
    for unit in fdcommander fdcommander-queue fdcommander-scheduler fdcommander-reverb; do
        [[ -f "/etc/systemd/system/${unit}.service" ]] || continue
        dir="/etc/systemd/system/${unit}.service.d"
        extra_paths=""
        [[ "$unit" == "fdcommander" ]] && extra_paths=" /var/lib/caddy"
        mkdir -p "$dir"
        cat > "${dir}/hardening.conf" <<HARDENEOF
# Written by deploy.sh/update.sh.
[Service]
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=${protect_home}
ProtectSystem=strict
ReadWritePaths=${APP_PATH}/storage ${APP_PATH}/bootstrap/cache${extra_paths}
HARDENEOF
    done
    systemctl daemon-reload
    return 0
}

# --- Backup and maintenance mode ---
# Migrations change the database, so back it up first, after entering
# maintenance mode so nothing is logged between the backup and the update.
# Both happen only once the code and assets are built, so the site stays up
# through the slow steps (composer, npm) and is down only for the backup,
# migrations and restart. Uploaded files are not touched by an update, so the
# database is enough.
# The script run is
# the root-owned installed copy if there is one, else the backup.sh next to
# this update.sh (the same trust as update.sh itself).
take_pre_update_backup() {
    if [[ $SKIP_BACKUP -eq 1 ]]; then
        log_warn "Skipping the pre-update backup (--skip-backup)"
        return 0
    fi

    local backup_script="/usr/local/sbin/fdcommander-backup"
    [[ -x "$backup_script" ]] || backup_script="$SCRIPT_DIR/backup.sh"
    if [[ ! -f "$backup_script" ]]; then
        log_warn "backup.sh not found — continuing without a pre-update backup"
        return 0
    fi

    log_phase "Backing up before update"
    # --keep-days 0: leave pruning to the scheduled backups' own retention.
    bash "$backup_script" --app-path "$APP_PATH" --db-only --keep-days 0
    return 0
}

MAINTENANCE_ON=0

leave_maintenance() {
    [[ $MAINTENANCE_ON -eq 1 ]] || return 0
    (cd "$APP_PATH" && sudo -u fdcommander php artisan up) \
        || log_warn "Could not leave maintenance mode — run: sudo -u fdcommander php artisan up"
    MAINTENANCE_ON=0
    return 0
}

# On any exit: leave maintenance mode and drop a FrankenPHP download that was
# never installed.
on_exit() {
    leave_maintenance
    if [[ -n "$FRANKENPHP_STAGED" ]]; then
        rm -f "$FRANKENPHP_STAGED"
    fi
    return 0
}

enter_maintenance() {
    log_info "Putting the app into maintenance mode for the update"
    (cd "$APP_PATH" && sudo -u fdcommander php artisan down --retry=60)
    MAINTENANCE_ON=1
    return 0
}

# --- Restart services ---
restart_services() {
    log_phase "Restarting services"

    install_staged_frankenphp
    migrate_octane_unit
    sync_octane_workers
    migrate_scheduler_unit
    rename_unit_descriptions
    migrate_queue_unit
    write_unit_hardening

    local unit
    for unit in "${APP_UNITS[@]}"; do
        systemctl restart "${unit}.service"
    done

    log_info "Services restarted"
}

# --- Check the result ---
APP_UNITS=(fdcommander fdcommander-queue fdcommander-reverb fdcommander-scheduler)

# unit_restarts <unit>: how many times systemd has restarted the unit.
unit_restarts() {
    systemctl show -p NRestarts --value "${1}.service" 2>/dev/null || echo 0
    return 0
}

# Wait until every unit has stayed active, without a restart, for a few
# seconds. A unit that crashes on start (bad config, unreadable certificate)
# shows as active between restarts, so a single is-active is not enough.
check_units_stay_up() {
    local stable_for=5 timeout=60 elapsed=0 i all_stable now
    local -a restarts=() stable=()
    for i in "${!APP_UNITS[@]}"; do
        restarts[i]=$(unit_restarts "${APP_UNITS[i]}")
        stable[i]=0
    done
    while (( elapsed < timeout )); do
        all_stable=1
        for i in "${!APP_UNITS[@]}"; do
            now=$(unit_restarts "${APP_UNITS[i]}")
            if [[ "$(systemctl is-active "${APP_UNITS[i]}.service" 2>/dev/null)" == "active" && "$now" == "${restarts[i]}" ]]; then
                stable[i]=$(( stable[i] + 1 ))
            else
                stable[i]=0
                restarts[i]=$now
            fi
            (( stable[i] >= stable_for )) || all_stable=0
        done
        (( all_stable )) && return 0
        sleep 1
        elapsed=$(( elapsed + 1 ))
    done

    log_error "These services did not stay running after the restart:"
    for i in "${!APP_UNITS[@]}"; do
        if (( stable[i] < stable_for )); then
            log_error "  ${APP_UNITS[i]} ($(systemctl is-active "${APP_UNITS[i]}.service" 2>/dev/null || true)): journalctl -u ${APP_UNITS[i]}.service -n 50 --no-pager"
        fi
    done
    return 1
}

# Ask the app's health route (/up) through Caddy, on this machine, using the
# site address from .env.
check_app_answers() {
    local address scheme hostport host port url code="000" tries
    address=$(get_env CADDY_SITE_ADDRESS)
    [[ -n "$address" ]] || address=$(get_env APP_URL)
    scheme="http"
    [[ "$address" == https://* ]] && scheme="https"
    hostport="${address#*://}"
    hostport="${hostport%%/*}"
    host="${hostport%:*}"
    port=""
    [[ "$hostport" == *:* ]] && port="${hostport##*:}"
    if [[ -z "$port" ]]; then
        port=80
        [[ "$scheme" == "https" ]] && port=443
    fi
    url="${scheme}://${host}:${port}/up"
    # A name is sent to this machine; an IP address is used as is, since
    # Caddy picks the certificate for a bare IP by the address connected to.
    local -a resolve=(--resolve "${host}:${port}:127.0.0.1")
    local -a resolve_http=(--resolve "${host}:80:127.0.0.1")
    if [[ "$host" =~ ^[0-9.]+$ || "$host" == \[* ]]; then
        resolve=()
        resolve_http=()
    fi

    for (( tries = 0; tries < 30; tries++ )); do
        code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 \
            ${resolve[@]+"${resolve[@]}"} "$url" 2>/dev/null || true)
        [[ "$code" == "200" ]] && return 0
        sleep 1
    done

    # HTTPS that never completes a handshake may just be a certificate Caddy
    # has not obtained yet (Let's Encrypt). The services are running, so only
    # warn when plain HTTP still reaches Caddy.
    if [[ "$scheme" == "https" && "$code" == "000" ]]; then
        local http_code
        http_code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
            ${resolve_http[@]+"${resolve_http[@]}"} "http://${host}/up" 2>/dev/null || true)
        if [[ "$http_code" =~ ^[23] ]]; then
            log_warn "Could not check ${url} over HTTPS (the certificate may not be issued yet)."
            log_warn "The services are running; open the site in a browser to confirm."
            return 0
        fi
    fi

    log_error "The app did not answer at ${url} (last status: ${code})."
    log_error "  Check: journalctl -u fdcommander.service -n 50 --no-pager"
    log_error "  and the app log in ${APP_PATH}/storage/logs/"
    return 1
}

verify_services() {
    log_phase "Checking services"
    check_units_stay_up || return 1
    check_app_answers || return 1
    log_info "All services are running and the app answers"
    return 0
}

# --- Main ---
main() {
    log_phase "Field Day Commander Update — $(date '+%Y-%m-%d %H:%M:%S')"

    trap on_exit EXIT
    repair_env_access
    fetch_updates
    pull_updates
    reexec_if_script_changed
    migrate_env_settings
    if prompt_redis_migration; then
        bootstrap_redis
        migrate_env_to_redis
    else
        log_warn "Skipping Redis bootstrap and .env driver migration"
    fi
    ensure_node_version
    install_dependencies
    stage_frankenphp_update
    harden_php_ini
    enter_maintenance
    take_pre_update_backup
    run_migrations
    rebuild_caches
    restart_services
    leave_maintenance
    if ! verify_services; then
        log_error "The update was installed, but Field Day Commander is not running correctly (see above)."
        log_error "Update log: ${LOG_FILE}"
        exit 1
    fi

    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}  Field Day Commander Update Complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    local app_version="unknown"
    if [[ -f "$APP_PATH/VERSION" ]]; then
        app_version=$(tr -d '[:space:]' < "$APP_PATH/VERSION")
    fi
    local git_sha
    git_sha=$(app_git rev-parse --short HEAD 2>/dev/null || echo 'unknown')

    echo -e "  Version:  ${app_version} (${git_sha})"
    echo -e "  App URL:  $(get_env APP_URL)"
    echo ""
    echo -e "${BOLD}Service Status${NC}"
    echo "  FrankenPHP:   $(systemctl is-active fdcommander.service)"
    echo "  Queue Worker: $(systemctl is-active fdcommander-queue.service)"
    echo "  Reverb:       $(systemctl is-active fdcommander-reverb.service)"
    echo "  Scheduler:    $(systemctl is-active fdcommander-scheduler.service)"
    echo ""
    echo "  Update log:   ${LOG_FILE}"
    echo ""
}

main
