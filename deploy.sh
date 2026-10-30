#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

set -euo pipefail

# --- Constants ---
SCRIPT_VERSION="1.0.0"
FRANKENPHP_VERSION="1.13.4"
# The glibc (-gnu) builds, which need glibc 2.28+. FrankenPHP's maintainers
# advise against the musl static builds in production.
# FrankenPHP rebuilds the latest release's binaries every night, so a pinned
# SHA-256 goes stale within a day. The install checks the download against the
# digest GitHub reports for the asset at that moment instead.
# update.sh reads FRANKENPHP_VERSION from this file.
NODE_MAJOR="22"
LOG_FILE="/var/log/fd-commander-deploy.log"
readonly DISTRO_DEBIAN="debian"
readonly DISTRO_RHEL="rhel"

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# --- Early root check (before log file redirect) ---
if [[ $EUID -ne 0 ]]; then
    echo -e "\033[0;31m[ERROR]\033[0m This script must be run as root (or via sudo)" >&2
    exit 1
fi

# Files this script creates are not world-writable; secrets (.env, the log)
# get their own stricter modes.
umask 022

# curl limited to HTTPS with TLS 1.2 or newer, including after redirects.
https_curl() {
    curl --proto '=https' --tlsv1.2 "$@"
}

# --- Logging ---
# All output already goes through the tee to $LOG_FILE set up below, so these
# only print; teeing here as well wrote every line to the log twice.
log_info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }
log_phase()   { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}"; }

# True when Node is missing or older than NODE_MAJOR (Vite needs a current LTS).
node_needs_install() {
    command -v node &>/dev/null || return 0
    local installed_major
    installed_major=$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)
    (( installed_major < NODE_MAJOR ))
}

# The log can hold secrets and hostnames, so keep it readable by root only.
(umask 077 && touch "$LOG_FILE")
chmod 600 "$LOG_FILE"

# Redirect all stdout/stderr to log file as well
exec > >(tee -a "$LOG_FILE") 2>&1

# --- Defaults ---
DOMAIN=""
DB_PASSWORD=""
DB_NAME="fd_commander"
DB_USER="fd_commander"
APP_PATH="/var/www/fd-commander"
BRANCH="main"
REPO_URL=""
REVERB_PORT="8080"
APP_PORT=""
SSL_ENABLED=false
SSL_EMAIL=""
SSL_CERT=""
SSL_KEY=""
TRUSTED_HOSTS=""
TRUSTED_PROXIES=""
NO_SEEDERS=false
DRY_RUN=false
DEMO_MODE=false
DB_SERVICE="mariadb"
REDIS_SERVICE="redis"

# Save original args for potential re-exec (password sanitization)
ORIG_ARGS=("$@")

usage() {
    cat <<'USAGE'
Usage: deploy.sh --domain <domain> [options]

Required:
  --domain <domain>       Domain name or IP for the application

Optional:
  --db-password <pass>    Database password (default: randomly generated)
  --db-name <name>        Database name (default: fd_commander)
  --db-user <user>        Database user (default: fd_commander)
  --app-path <path>       Install path (default: /var/www/fd-commander)
  --branch <branch>       Git branch to deploy (default: main)
  --repo-url <url>        Git repo URL (default: copy current directory)
  --reverb-port <port>    Reverb WebSocket port (default: 8080)
  --port <port>           App port (default: 443 with SSL, 80 without)
  --ssl                   Enable HTTPS (Caddy auto-SSL or custom cert)
  --email <email>         Email for Let's Encrypt (required with --ssl)
  --ssl-cert <path>       Path to existing SSL certificate
  --ssl-key <path>        Path to existing SSL key
  --trusted-hosts <list>  Other hostnames users reach the site by, comma-separated
                          (e.g. a public domain alongside --domain). The --domain
                          host, private IPs and .local names always work.
  --trusted-proxies <list>
                          Reverse proxies in front of Field Day Commander whose
                          X-Forwarded-For headers to believe, as IPs or CIDRs,
                          comma-separated (e.g. 192.168.1.10). Default: none
                          besides this machine (127.0.0.1, ::1).
  --no-seeders            Skip database seeders
  --demo                  Deploy as a public demo instance (per-visitor sandboxed databases)
  --dry-run               Show plan without executing
  -h, --help              Show this help message
USAGE
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)       DOMAIN="$2"; shift 2 ;;
        --db-password)  DB_PASSWORD="$2"; shift 2 ;;
        --db-name)      DB_NAME="$2"; shift 2 ;;
        --db-user)      DB_USER="$2"; shift 2 ;;
        --app-path)     APP_PATH="$2"; shift 2 ;;
        --branch)       BRANCH="$2"; shift 2 ;;
        --repo-url)     REPO_URL="$2"; shift 2 ;;
        --reverb-port)  REVERB_PORT="$2"; shift 2 ;;
        --port)         APP_PORT="$2"; shift 2 ;;
        --ssl)          SSL_ENABLED=true; shift ;;
        --email)        SSL_EMAIL="$2"; shift 2 ;;
        --ssl-cert)     SSL_CERT="$2"; shift 2 ;;
        --ssl-key)      SSL_KEY="$2"; shift 2 ;;
        --trusted-hosts) TRUSTED_HOSTS="$2"; shift 2 ;;
        --trusted-proxies) TRUSTED_PROXIES="$2"; shift 2 ;;
        --no-seeders)   NO_SEEDERS=true; shift ;;
        --demo)         DEMO_MODE=true; NO_SEEDERS=true; shift ;;
        --dry-run)      DRY_RUN=true; shift ;;
        -h|--help)      usage 0 ;;
        *)              log_error "Unknown option: $1"; usage 1 ;;
    esac
done

# path_in_private_tmp <path>: whether the path (after resolving symlinks) is
# under /tmp or /var/tmp. The services run with PrivateTmp=true, so they see
# their own empty /tmp and cannot read a certificate kept there.
# update.sh carries the same function.
path_in_private_tmp() {
    local resolved
    resolved=$(realpath -m -- "$1" 2>/dev/null || echo "$1")
    [[ "$resolved" == /tmp/* || "$resolved" == /var/tmp/* ]]
}

# --- Validation ---
validate_args() {
    local errors=0

    if [[ -z "$DOMAIN" ]]; then
        log_error "--domain is required"
        errors=$((errors + 1))
    fi

    # SSL validation
    if [[ -n "$SSL_CERT" || -n "$SSL_KEY" ]] && ! $SSL_ENABLED; then
        log_error "--ssl-cert and --ssl-key require --ssl flag"
        errors=$((errors + 1))
    fi

    if $SSL_ENABLED && [[ -z "$SSL_CERT" ]] && [[ -z "$SSL_EMAIL" ]]; then
        log_error "--email is required for Let's Encrypt (--ssl without --ssl-cert)"
        errors=$((errors + 1))
    fi

    if [[ -n "$SSL_CERT" ]] && [[ -z "$SSL_KEY" ]]; then
        log_error "--ssl-cert requires --ssl-key"
        errors=$((errors + 1))
    fi

    if [[ -n "$SSL_KEY" ]] && [[ -z "$SSL_CERT" ]]; then
        log_error "--ssl-key requires --ssl-cert"
        errors=$((errors + 1))
    fi

    if [[ -n "$SSL_CERT" ]] && [[ ! -f "$SSL_CERT" ]]; then
        log_error "SSL certificate not found: $SSL_CERT"
        errors=$((errors + 1))
    fi

    if [[ -n "$SSL_KEY" ]] && [[ ! -f "$SSL_KEY" ]]; then
        log_error "SSL key not found: $SSL_KEY"
        errors=$((errors + 1))
    fi

    # Interpolated into SQL identifiers and .env, so keep them plain.
    if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
        log_error "--db-name may only contain letters, digits and underscores"
        errors=$((errors + 1))
    fi

    if [[ ! "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
        log_error "--db-user may only contain letters, digits and underscores"
        errors=$((errors + 1))
    fi

    # The password goes into .env unquoted (also read by systemd), where
    # whitespace, quotes, $, # and backslashes are mangled.
    if [[ "$DB_PASSWORD" =~ [[:space:]\"\'\$\#\`\\] ]]; then
        log_error "--db-password must not contain whitespace, quotes, \$, #, \` or backslashes"
        errors=$((errors + 1))
    fi

    # Written into the Caddyfile as a bare word.
    if [[ -n "$SSL_EMAIL" && ! "$SSL_EMAIL" =~ ^[^[:space:]\"{}#]+@[^[:space:]\"{}#]+$ ]]; then
        log_error "--email must be a plain email address"
        errors=$((errors + 1))
    fi

    # Written into the Caddyfile inside double quotes.
    if [[ "$SSL_CERT$SSL_KEY" == *\"* ]]; then
        log_error "--ssl-cert and --ssl-key paths must not contain double quotes"
        errors=$((errors + 1))
    fi

    # The services cannot see /tmp or /var/tmp (PrivateTmp), so Caddy would
    # fail to start with a certificate kept there.
    local tls_file
    for tls_file in "$SSL_CERT" "$SSL_KEY"; do
        if [[ -n "$tls_file" ]] && path_in_private_tmp "$tls_file"; then
            log_error "${tls_file} is under /tmp or /var/tmp, which the Field Day Commander services cannot see."
            log_error "Move the certificate and key somewhere permanent (e.g. /etc/ssl/fd-commander/) and re-run."
            errors=$((errors + 1))
        fi
    done

    # A browser would not trust an expired certificate either.
    if [[ -f "$SSL_CERT" ]] && command -v openssl &>/dev/null \
        && ! openssl x509 -checkend 0 -noout -in "$SSL_CERT" &>/dev/null; then
        log_error "SSL certificate ${SSL_CERT} has expired or is not a PEM certificate"
        errors=$((errors + 1))
    fi

    # Written into .env unquoted: hostnames, IPs and "*" only.
    if [[ -n "$TRUSTED_HOSTS" && ! "$TRUSTED_HOSTS" =~ ^[A-Za-z0-9.:*,_-]+$ ]]; then
        log_error "--trusted-hosts must be a comma-separated list of hostnames or IPs, without spaces"
        errors=$((errors + 1))
    fi

    # Written into .env unquoted: IPv4/IPv6 addresses or CIDRs, or "*".
    if [[ -n "$TRUSTED_PROXIES" ]]; then
        local proxy
        local -a proxies=()
        IFS=',' read -r -a proxies <<< "$TRUSTED_PROXIES"
        for proxy in "${proxies[@]}"; do
            if [[ ! "$proxy" =~ ^([0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?|[0-9A-Fa-f]*:[0-9A-Fa-f:.]*(/[0-9]{1,3})?|\*)$ ]]; then
                log_error "--trusted-proxies must be a comma-separated list of IP addresses or CIDRs (got '${proxy}')"
                errors=$((errors + 1))
                break
            fi
        done
    fi

    if [[ $errors -gt 0 ]]; then
        echo ""
        usage 1
    fi
}

# --- Distro Detection ---
detect_distro() {
    if [[ ! -f /etc/os-release ]]; then
        log_error "Cannot detect distribution: /etc/os-release not found"
        exit 1
    fi

    source /etc/os-release

    if [[ "$ID" == "ubuntu" ]] || [[ "$ID" == "$DISTRO_DEBIAN" ]] || [[ "$ID" == "raspbian" ]]; then
        DISTRO_FAMILY="$DISTRO_DEBIAN"
        PKG_MANAGER="apt"
        WEB_GROUP="www-data"
        REDIS_SERVICE="redis-server"
    elif [[ "$ID" == "$DISTRO_RHEL" ]] || [[ "$ID" == "almalinux" ]] || [[ "$ID" == "rocky" ]] || [[ "${ID_LIKE:-}" == *"$DISTRO_RHEL"* ]] || [[ "${ID_LIKE:-}" == *"fedora"* ]]; then
        DISTRO_FAMILY="$DISTRO_RHEL"
        PKG_MANAGER="dnf"
        WEB_GROUP="www-data"
        REDIS_SERVICE="redis"
    else
        log_error "Unsupported distribution: $ID"
        exit 1
    fi

    log_info "Detected distro family: ${DISTRO_FAMILY} ($PRETTY_NAME)"
}

# --- Secret Generation ---
generate_secret() {
    local length="${1:-32}"
    # || true needed: head closes pipe early, causing tr to get SIGPIPE (exit 141) under pipefail
    tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length" || true
}

# Generate DB password if not provided
if [[ -z "$DB_PASSWORD" ]]; then
    DB_PASSWORD=$(generate_secret 32)
    DB_PASSWORD_GENERATED=true
else
    DB_PASSWORD_GENERATED=false
fi

# Generate demo provisioner password when in demo mode
if $DEMO_MODE; then
    DEMO_DB_PASSWORD=$(generate_secret 32)
    DEMO_DB_USER="demo_provisioner"
    DEMO_DB_NAME="demo_base"
fi

# If DB password was passed via CLI, re-exec with it as an env var to hide from /proc/cmdline
if [[ -z "${_FDC_DB_PASS_FROM_ENV:-}" ]] && [[ -n "$DB_PASSWORD" ]] && ! $DB_PASSWORD_GENERATED; then
    export _FDC_DB_PASS_FROM_ENV="$DB_PASSWORD"
    # Re-exec using saved ORIG_ARGS, stripping --db-password and its value
    new_args=()
    skip_next=false
    for arg in "${ORIG_ARGS[@]}"; do
        if $skip_next; then
            skip_next=false
            continue
        fi
        if [[ "$arg" == "--db-password" ]]; then
            skip_next=true
            continue
        fi
        new_args+=("$arg")
    done
    exec "$0" "${new_args[@]}"
fi

# Pick up password from env if re-exec'd
if [[ -n "${_FDC_DB_PASS_FROM_ENV:-}" ]]; then
    DB_PASSWORD="$_FDC_DB_PASS_FROM_ENV"
    DB_PASSWORD_GENERATED=false
    unset _FDC_DB_PASS_FROM_ENV
fi

# --- Derived Values ---
if $SSL_ENABLED; then
    SCHEME="https"
    DEFAULT_PORT="443"
else
    SCHEME="http"
    DEFAULT_PORT="80"
fi
# Use explicit --port if given, otherwise the protocol default
APP_PORT="${APP_PORT:-$DEFAULT_PORT}"
# PUBLIC_PORT is what browsers connect to (used for Reverb/Vite env vars)
PUBLIC_PORT="$APP_PORT"
# Whether to append :port to URLs (omit for standard 80/443)
if [[ "$APP_PORT" == "80" || "$APP_PORT" == "443" ]]; then
    PORT_SUFFIX=""
else
    PORT_SUFFIX=":${APP_PORT}"
fi

# --- Dry Run ---
print_dry_run() {
    echo -e "\n${BOLD}Field Day Commander Deployment Plan${NC}"
    echo "=============================="
    echo "Domain:         $DOMAIN"
    echo "App URL:        ${SCHEME}://${DOMAIN}${PORT_SUFFIX}"
    echo "App Port:       $APP_PORT"
    echo "App Path:       $APP_PATH"
    echo "Distro:         $DISTRO_FAMILY ($PRETTY_NAME)"
    echo "Database:       $DB_NAME (user: $DB_USER)"
    echo "Reverb Port:    $REVERB_PORT (internal, proxied via Caddy)"
    echo "SSL:            $( $SSL_ENABLED && echo "Yes" || echo "No" )"
    if $SSL_ENABLED; then
        if [[ -n "$SSL_CERT" ]]; then
            echo "SSL Method:     Custom certificate"
        else
            echo "SSL Method:     Let's Encrypt (email: $SSL_EMAIL)"
        fi
    fi
    if [[ -n "$TRUSTED_HOSTS" ]]; then
        echo "Trusted Hosts:  $TRUSTED_HOSTS"
    fi
    if [[ -n "$TRUSTED_PROXIES" ]]; then
        echo "Trusted Proxies: $TRUSTED_PROXIES"
    fi
    echo "Source:         $( [[ -n "$REPO_URL" ]] && echo "$REPO_URL (branch: $BRANCH)" || echo "Copy current directory" )"
    echo "Demo Mode:      $( $DEMO_MODE && echo "Yes (per-visitor sandboxed databases)" || echo "No" )"
    echo "Seeders:        $( $NO_SEEDERS && echo "Skipped" || echo "Production seeders" )"
    echo ""
    echo "Cache/Queue/Session: Redis (AOF fsync=always, volatile-lru)"
    echo "Phases: Packages → App Setup → Redis → Database → Caddy → SSL → Systemd → Firewall → Cache"
}

# --- NodeSource repos ---
# NodeSource's documented manual setup (signed repo + keyring) rather than
# piping its setup script into a root shell; apt/dnf verify every package.
# update.sh carries the same two functions.
add_nodesource_repo_debian() {
    log_info "Adding NodeSource repo for Node ${NODE_MAJOR}..."
    install -d -m 0755 /etc/apt/keyrings
    https_curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
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

# --- Package Installation ---
install_packages_debian() {
    log_phase "Phase 2: Installing system packages (Debian/Ubuntu)"

    source /etc/os-release
    local codename="${VERSION_CODENAME:-}"

    apt-get update -y
    apt-get install -y ca-certificates curl gnupg

    # PHP 8.4 ships natively on Debian 13+ (trixie) and any distro where apt already
    # lists php8.4-cli. Only fall back to a third-party repo (Sury for Debian/Raspbian,
    # ondrej/php PPA for Ubuntu) when the native archives don't carry it.
    if command -v php8.4 &>/dev/null; then
        log_warn "PHP 8.4 already installed, skipping repo setup"
    elif apt-cache show php8.4-cli &>/dev/null; then
        log_info "PHP 8.4 available in default repos (${ID} ${codename}), skipping third-party repo"
    elif [[ "$ID" == "ubuntu" ]]; then
        log_info "Adding ondrej/php PPA for Ubuntu ${codename}..."
        install -d -m 0755 /usr/share/keyrings
        curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' \
            | gpg --dearmor --yes -o /usr/share/keyrings/ondrej-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${codename} main" \
            > /etc/apt/sources.list.d/ondrej-php.list
        apt-get update -y
    else
        log_info "Adding Sury PHP DEB repo for ${ID} ${codename}..."
        curl -fsSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${codename} main" \
            > /etc/apt/sources.list.d/sury-php.list
        apt-get update -y
    fi

    # Add NodeSource repo
    if node_needs_install; then
        add_nodesource_repo_debian
    else
        log_warn "Node.js $(node --version) already installed, skipping NodeSource"
    fi

    log_info "Installing packages..."
    apt-get install -y \
        php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
        php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl php8.4-redis \
        mariadb-server redis-server nodejs unzip git
}

install_packages_rhel() {
    log_phase "Phase 2: Installing system packages (RHEL-family)"

    # EPEL + Remi repos
    log_info "Enabling EPEL and Remi repos..."
    dnf install -y epel-release
    dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm || true
    dnf module reset php -y
    dnf module enable php:remi-8.4 -y

    # NodeSource repo
    if node_needs_install; then
        log_info "Adding NodeSource repo for Node ${NODE_MAJOR}..."
        add_nodesource_repo_rhel
    else
        log_warn "Node.js $(node --version) already installed, skipping NodeSource"
    fi

    log_info "Installing packages..."
    dnf install -y \
        php-cli php-mysqlnd php-mbstring php-xml \
        php-curl php-zip php-bcmath php-gd php-intl php-redis \
        mariadb-server redis nodejs unzip git

    # SELinux: allow FrankenPHP network access and set the binary's context
    # (applied by install_frankenphp once the binary is in place).
    if selinux_enabled; then
        log_info "Configuring SELinux for FrankenPHP..."
        setsebool -P httpd_can_network_connect on
        ensure_semanage
        selinux_fcontext httpd_exec_t '/usr/local/bin/frankenphp'
    else
        log_info "SELinux is not enabled; skipping its FrankenPHP settings"
    fi
}

install_composer() {
    if command -v composer &>/dev/null; then
        log_warn "Composer already installed, skipping"
        return
    fi

    log_info "Installing Composer..."
    local expected_sig
    expected_sig=$(https_curl -fsSL https://composer.github.io/installer.sig)
    local installer="${DEPLOY_WORK_DIR}/composer-setup.php"
    https_curl -fsSL -o "$installer" https://getcomposer.org/installer
    local actual_sig
    actual_sig=$(php -r "echo hash_file('sha384', \$argv[1]);" "$installer")

    if [[ "$expected_sig" != "$actual_sig" ]]; then
        log_error "Composer installer signature mismatch"
        rm -f "$installer"
        exit 1
    fi

    php "$installer" --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"
    fapolicyd_trust /usr/local/bin/composer
    log_info "Composer installed successfully"
}

create_system_user() {
    if id "fdcommander" &>/dev/null; then
        log_warn "User 'fdcommander' already exists, skipping"
        return
    fi

    # Ensure the web group exists (www-data may not exist on RHEL)
    if ! getent group www-data &>/dev/null; then
        groupadd www-data
    fi

    log_info "Creating system user 'fdcommander'..."
    useradd --system --no-create-home --shell /usr/sbin/nologin -g "$WEB_GROUP" fdcommander
}

# The web group is the app user's own group. update.sh and restore.sh read it
# the same way (id -gn fdcommander), so every script agrees even on a server
# where the user was created with another group.
use_app_user_group() {
    WEB_GROUP=$(id -gn fdcommander)
    return 0
}

# Print the SHA-256 GitHub reports for a FrankenPHP release asset. FrankenPHP
# publishes no checksum file, and its assets are rebuilt nightly. Uses the PHP
# CLI to read the JSON, since jq isn't installed.
frankenphp_asset_sha256() {
    local version="$1" asset="$2"
    https_curl -fsSL \
        "https://api.github.com/repos/php/frankenphp/releases/tags/v${version}" \
        | php -r '
            $release = json_decode(stream_get_contents(STDIN), true);
            foreach ($release["assets"] ?? [] as $a) {
                if ($a["name"] === $argv[1] && preg_match("/^sha256:([0-9a-f]{64})$/", $a["digest"] ?? "", $m)) {
                    echo $m[1];
                    exit(0);
                }
            }
            exit(1);
        ' "$asset"
}

install_frankenphp() {
    log_info "Installing FrankenPHP ${FRANKENPHP_VERSION}..."
    local arch
    case "$(uname -m)" in
        x86_64)  arch="linux-x86_64-gnu" ;;
        aarch64) arch="linux-aarch64-gnu" ;;
        *)       log_error "Unsupported architecture: $(uname -m)"; exit 1 ;;
    esac
    local url="https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-${arch}"
    local dest="/usr/local/bin/frankenphp"
    local tmp="${dest}.new"
    log_info "Downloading FrankenPHP from ${url}..."
    https_curl -fSL -o "$tmp" "$url"
    local expected_sha
    if ! expected_sha=$(frankenphp_asset_sha256 "$FRANKENPHP_VERSION" "frankenphp-${arch}"); then
        rm -f "$tmp"
        log_error "Could not read the SHA-256 of frankenphp-${arch} ${FRANKENPHP_VERSION} from GitHub"
        exit 1
    fi
    if ! echo "${expected_sha}  ${tmp}" | sha256sum --check --quiet --status; then
        rm -f "$tmp"
        log_error "FrankenPHP download does not match the SHA-256 GitHub reports (${expected_sha}). Run deploy.sh again; the release may have been rebuilt mid-download."
        exit 1
    fi
    chmod 755 "$tmp"
    mv -f "$tmp" "$dest"
    setcap cap_net_bind_service=+ep "$dest"
    if selinux_enabled; then
        restorecon "$dest"
    fi
    fapolicyd_trust "$dest"
    if ! "$dest" version &>/dev/null; then
        log_error "FrankenPHP binary verification failed"
        exit 1
    fi
    # A plain version check passes even on a build that crashes on this CPU,
    # and Laravel hashes with SHA-256 on nearly every request.
    if ! "$dest" php-cli -r 'hash("sha256", "x");' &>/dev/null; then
        log_error "FrankenPHP crashed running PHP on this CPU"
        exit 1
    fi
    log_info "FrankenPHP $("$dest" version) installed to ${dest}"
}

configure_php_ini() {
    local ini_dir="/etc/frankenphp"
    local ini_file="${ini_dir}/php.ini"
    log_info "Configuring PHP settings for FrankenPHP..."
    mkdir -p "$ini_dir"
    cat > "$ini_file" <<'PHPEOF'
; Field Day Commander PHP configuration for FrankenPHP
upload_max_filesize = 30M
post_max_size = 35M
memory_limit = 256M
max_execution_time = 60
; Laravel never uses PHP's native sessions. update.sh adds this to older installs.
session.upload_progress.enabled = Off
PHPEOF
    log_info "PHP configuration written to ${ini_file}"
}

# --- Hardened systems: SELinux, fapolicyd ---
# Each step checks for the feature first and logs when it is skipped, so
# nothing changes on a system without it. Safe to re-run. update.sh carries
# the same functions.

selinux_enabled() {
    command -v selinuxenabled &>/dev/null && selinuxenabled
}

# The app path as a file-context regex (dots escaped).
selinux_app_regex() {
    printf '%s' "${APP_PATH//./\\.}"
    return 0
}

ensure_semanage() {
    command -v semanage &>/dev/null && return 0
    log_info "SELinux: installing policycoreutils-python-utils for semanage..."
    if [[ "$PKG_MANAGER" == "dnf" ]]; then
        dnf install -y policycoreutils-python-utils
    else
        apt-get install -y policycoreutils-python-utils
    fi
    return 0
}

# selinux_fcontext <type> <regex>: a persistent file-context rule. Added
# once, changed in place if it exists with another type, left alone if it
# already matches.
selinux_fcontext() {
    local type="$1" spec="$2" current
    # ENVIRON, not awk -v, which would turn the regex's \. into a plain dot.
    current=$(semanage fcontext -l -C 2>/dev/null | SPEC="$spec" awk '$1 == ENVIRON["SPEC"] { split($NF, c, ":"); print c[3] }')
    if [[ "$current" == "$type" ]]; then
        log_info "SELinux: ${spec} is already ${type}"
    elif [[ -n "$current" ]]; then
        log_info "SELinux: changing ${spec} from ${current} to ${type}"
        semanage fcontext -m -t "$type" "$spec"
    else
        log_info "SELinux: labeling ${spec} as ${type}"
        semanage fcontext -a -t "$type" "$spec"
    fi
    return 0
}

# Label the app tree. systemd reads EnvironmentFile= as PID 1 (init_t)
# before it switches to the service user, and init_t cannot read the
# var_t or httpd content labels a fresh tree gets, so .env is etc_t.
# Everything else takes the policy defaults. Run after every .env write:
# sed -i and other replace-by-rename writes drop the label.
selinux_label_app() {
    if ! selinux_enabled; then
        log_info "SELinux is not enabled; skipping file labels"
        return 0
    fi
    ensure_semanage
    selinux_fcontext etc_t "$(selinux_app_regex)/\.env"
    # A parent such as /var/www made by mkdir or git clone gets var_t. Only
    # the directory itself: other sites under it keep their labels.
    restorecon -F "$(dirname "$APP_PATH")"
    restorecon -RF "$APP_PATH"
    log_info "SELinux: relabeled ${APP_PATH} ($(stat -c '%C' "$APP_PATH/.env" 2>/dev/null | cut -d: -f3) on .env)"
    return 0
}

FAPOLICYD_RULES="/etc/fapolicyd/rules.d/39-fdcommander.rules"
FAPOLICYD_TRUST_FILE="fdcommander"
FAPOLICYD_TRUST_DB="/etc/fapolicyd/trust.d/${FAPOLICYD_TRUST_FILE}"

fapolicyd_active() {
    systemctl is-active --quiet fapolicyd 2>/dev/null
}

# fapolicyd_trust <file>...: add the files, or their new contents, to
# fapolicyd's trust database. No-op when fapolicyd isn't running.
fapolicyd_trust() {
    fapolicyd_active || return 0
    local file
    for file in "$@"; do
        [[ -e "$file" ]] || continue
        if grep -qs "^${file} " "$FAPOLICYD_TRUST_DB"; then
            fapolicyd-cli --file update "$file" --trust-file "$FAPOLICYD_TRUST_FILE"
        else
            fapolicyd-cli --file add "$file" --trust-file "$FAPOLICYD_TRUST_FILE"
        fi
        log_info "fapolicyd: trusted ${file}"
    done
    fapolicyd-cli --update
    return 0
}

# fapolicyd_untrust <file>: drop a file (such as a staged download) from
# the trust database.
fapolicyd_untrust() {
    fapolicyd_active || return 0
    local file="$1"
    if grep -qs "^${file} " "$FAPOLICYD_TRUST_DB"; then
        fapolicyd-cli --file delete "$file" --trust-file "$FAPOLICYD_TRUST_FILE"
        fapolicyd-cli --update
    fi
    return 0
}

# fapolicyd's stock rules stop interpreters opening PHP and JavaScript files
# no package vouches for, which is all of the app, its compiled views and
# the Vite build's native modules. Allow just those interpreters, just under
# the app path. Numbered 39 so it comes before the stock deny rules (40+).
configure_fapolicyd() {
    if ! fapolicyd_active; then
        log_info "fapolicyd is not running; skipping its rules"
        return 0
    fi
    local rules
    rules=$(cat <<FAPEOF
# Written by deploy.sh/update.sh. Lets PHP, FrankenPHP and Node load
# Field Day Commander's own code under ${APP_PATH}.
allow perm=open exe=/usr/bin/php : dir=${APP_PATH}/
allow perm=open exe=/usr/bin/php : dir=/var/lib/fd-commander/deploy-tmp/
allow perm=open exe=/usr/local/bin/frankenphp : dir=${APP_PATH}/
allow perm=any exe=/usr/bin/node : dir=${APP_PATH}/node_modules/
allow perm=open exe=/usr/bin/node : dir=${APP_PATH}/
FAPEOF
)
    if [[ -f "$FAPOLICYD_RULES" && "$(cat "$FAPOLICYD_RULES")" == "$rules" ]]; then
        log_info "fapolicyd: rules in ${FAPOLICYD_RULES} are up to date"
    else
        printf '%s\n' "$rules" > "$FAPOLICYD_RULES"
        chmod 644 "$FAPOLICYD_RULES"
        log_info "fapolicyd: wrote ${FAPOLICYD_RULES}"
        fagenrules --load
    fi
    fapolicyd_trust /usr/local/bin/frankenphp /usr/local/bin/composer
    return 0
}

# --- Work directory for downloads and builds ---
# A hardened /tmp is often mounted noexec, which breaks installers and build
# tools that unpack and run code there. When it is, point TMPDIR at a
# directory that allows exec instead, and remove it when the deploy ends.
DEPLOY_WORK_DIR="/var/lib/fd-commander/deploy-tmp"
APP_TMP_ENV=()

mount_is_noexec() {
    local path="$1"
    findmnt -no OPTIONS --target "$path" 2>/dev/null | tr ',' '\n' | grep -qx noexec
}

prepare_work_dir() {
    install -d -m 0770 -o root -g "$WEB_GROUP" "$DEPLOY_WORK_DIR"
    if ! mount_is_noexec /tmp; then
        log_info "/tmp allows exec; Composer and npm use it as usual"
        return 0
    fi
    if mount_is_noexec "$DEPLOY_WORK_DIR"; then
        log_warn "/tmp and ${DEPLOY_WORK_DIR} are both mounted noexec; Composer or npm may fail"
        return 0
    fi
    APP_TMP_ENV=(TMPDIR="$DEPLOY_WORK_DIR")
    export TMPDIR="$DEPLOY_WORK_DIR"
    log_info "/tmp is mounted noexec; using ${DEPLOY_WORK_DIR} for temporary files"
    return 0
}

cleanup_work_dir() {
    rm -rf "$DEPLOY_WORK_DIR"
    return 0
}

# --- Checking the result ---
APP_UNITS=(fdcommander fdcommander-queue fdcommander-reverb fdcommander-scheduler)

# unit_restarts <unit>: how many times systemd has restarted the unit.
unit_restarts() {
    local unit="$1"
    systemctl show -p NRestarts --value "${unit}.service" 2>/dev/null || echo 0
    return 0
}

# Wait until every unit has stayed active, without a restart, for a few
# seconds. A unit that crashes on start shows as active between restarts,
# so a single is-active is not enough. update.sh carries the same check.
check_units_stay_up() {
    local stable_for=5 timeout=60 elapsed=0 i all_stable now
    local -a restarts=() stable=()
    FAILED_UNITS=()
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
    for i in "${!APP_UNITS[@]}"; do
        (( stable[i] >= stable_for )) || FAILED_UNITS+=("${APP_UNITS[i]}")
    done
    return 1
}

# Print what went wrong for each unit that did not stay up, with any SELinux
# denials. Never switches SELinux to permissive.
print_unit_failures() {
    local unit
    for unit in "${FAILED_UNITS[@]}"; do
        log_error "${unit}.service did not stay running ($(systemctl is-active "${unit}.service" 2>/dev/null || true)). Last log lines:"
        journalctl -u "${unit}.service" -n 50 --no-pager 2>/dev/null || true
    done
    if selinux_enabled && command -v ausearch &>/dev/null; then
        log_error "Recent SELinux denials (empty means none):"
        ausearch -m AVC -ts recent 2>/dev/null || true
    fi
    return 0
}

# The SELinux domain the FrankenPHP server process runs in. On a stock
# install systemd starts the PHP CLI in unconfined_service_t and FrankenPHP
# stays there. If it lands in a confined web domain (httpd_t), it needs
# write labels on storage/, bootstrap/cache and Caddy's data, and its port.
check_web_domain() {
    selinux_enabled || return 0
    local domain="" tries
    for (( tries = 0; tries < 10; tries++ )); do
        domain=$(ps -eZ -o label=,comm= 2>/dev/null | awk '$2 == "frankenphp" { split($1, c, ":"); print c[3]; exit }')
        [[ -n "$domain" ]] && break
        sleep 1
    done
    case "$domain" in
        unconfined_service_t)
            log_info "SELinux: FrankenPHP runs as unconfined_service_t, as expected; no extra labels needed"
            ;;
        httpd_t)
            log_warn "SELinux: FrankenPHP runs confined as httpd_t; labeling its writable paths and port"
            local app_re
            app_re=$(selinux_app_regex)
            selinux_fcontext httpd_sys_rw_content_t "${app_re}/storage(/.*)?"
            selinux_fcontext httpd_sys_rw_content_t "${app_re}/bootstrap/cache(/.*)?"
            selinux_fcontext httpd_var_lib_t "/var/lib/caddy(/.*)?"
            restorecon -RF "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" /var/lib/caddy
            if ! semanage port -l | awk '$1 == "http_port_t" && $2 == "tcp"' | grep -qE "[ ,]${APP_PORT}(,|$)"; then
                semanage port -a -t http_port_t -p tcp "$APP_PORT"
                log_info "SELinux: added tcp/${APP_PORT} to http_port_t"
            fi
            setsebool -P httpd_can_network_connect on
            systemctl restart fdcommander.service
            ;;
        "")
            log_warn "SELinux: no FrankenPHP process found to check its domain"
            ;;
        *)
            log_warn "SELinux: FrankenPHP runs as ${domain}. If it misbehaves, check: ausearch -m AVC -ts recent"
            ;;
    esac
    return 0
}

verify_deploy() {
    log_phase "Phase 10: Checking services"
    if ! check_units_stay_up; then
        print_unit_failures
        return 1
    fi
    check_web_domain
    if ! check_units_stay_up; then
        print_unit_failures
        return 1
    fi
    log_info "All services are running"
    return 0
}

# How getting the HTTPS certificate went, and what to do if it didn't.
# Caddy requests a Let's Encrypt certificate in the background once the web
# server starts and keeps retrying, so a missing one doesn't fail the deploy.
CADDY_CERT_STORE="/var/lib/caddy/.local/share/caddy/certificates"

report_certificate() {
    $SSL_ENABLED || return 0
    log_phase "Phase 11: Checking the HTTPS certificate"

    if [[ -n "$SSL_CERT" ]]; then
        log_info "Serving your certificate ${SSL_CERT}"
        log_info "  $(openssl x509 -noout -subject -in "$SSL_CERT" 2>/dev/null), expires $(openssl x509 -noout -enddate -in "$SSL_CERT" 2>/dev/null | cut -d= -f2)"
        return 0
    fi

    local host="${DOMAIN,,}" cert="" waited=0
    log_info "Waiting up to 90 seconds for Caddy to get a certificate for ${host}..."
    while (( waited < 90 )); do
        cert=$(find "$CADDY_CERT_STORE" -path "*/${host}/${host}.crt" -print -quit 2>/dev/null || true)
        [[ -n "$cert" ]] && break
        sleep 3
        waited=$(( waited + 3 ))
    done

    if [[ -n "$cert" ]]; then
        log_info "Caddy got a certificate for ${host}. It expires $(openssl x509 -noout -enddate -in "$cert" 2>/dev/null | cut -d= -f2) and renews automatically."
        return 0
    fi

    local errors
    errors=$(journalctl -u fdcommander.service --since "$DEPLOY_STARTED" --no-pager -o cat 2>/dev/null \
        | grep -iE 'acme|challenge|obtain' | grep -iE 'error|fail' | tail -5 || true)
    log_warn "No certificate for ${host} yet. The site won't load over HTTPS until it has one."
    if [[ -n "$errors" ]]; then
        log_warn "Latest errors from Caddy:"
        printf '%s\n' "$errors"
    fi
    log_warn "Caddy keeps retrying on its own. Things to check:"
    log_warn "  - ${host} must resolve to this server's public IP address from the internet."
    log_warn "  - Ports 443 and 80 must be reachable from the internet. Check port forwarding on"
    log_warn "    your router, any cloud firewall, and this server's firewall."
    log_warn "  - After several failed attempts, Let's Encrypt makes you wait about an hour."
    log_warn "  - A LAN IP or .local name can't get a certificate. Re-run with --ssl-cert and"
    log_warn "    --ssl-key and your own certificate, or run without --ssl."
    log_warn "Watch progress with: journalctl -u fdcommander.service -f | grep -i acme"
    log_warn "Once it's fixed, restart the web server: systemctl restart fdcommander.service"
    return 0
}

install_packages() {
    case "$DISTRO_FAMILY" in
        debian) install_packages_debian ;;
        rhel)   install_packages_rhel ;;
        *)
            log_error "Unsupported distro family: ${DISTRO_FAMILY}"
            exit 1
            ;;
    esac

    create_system_user
    use_app_user_group
    prepare_work_dir
    configure_fapolicyd
    install_composer
    install_frankenphp
    configure_php_ini
}

# Helper to set env values (handles sed metacharacters safely)
# Must be defined at top level so configure_ssl() can also use it
set_env() {
    local env_file="$APP_PATH/.env"
    local key="$1" value="$2"
    # Remove any existing line (commented or not) for this key
    sed -i "/^#\?${key}=/d" "$env_file"
    # Append the new value (avoids sed substitution metacharacter issues)
    echo "${key}=${value}" >> "$env_file"
}

configure_env() {
    local env_file="$APP_PATH/.env"

    if [[ -f "$env_file" ]] && ! grep -q 'APP_ENV=local' "$env_file"; then
        log_warn ".env already configured for non-local environment, skipping"
        return
    fi

    log_info "Configuring .env for production..."
    cp "$APP_PATH/.env.example" "$env_file"

    # Generate Reverb secrets
    local reverb_app_key
    reverb_app_key=$(generate_secret 20)
    local reverb_app_secret
    reverb_app_secret=$(generate_secret 20)
    local reverb_app_id
    reverb_app_id=$(generate_secret 8)

    set_env "APP_NAME" '"Field Day Commander"'
    set_env "APP_ENV" "production"
    set_env "APP_DEBUG" "false"
    set_env "APP_URL" "${SCHEME}://${DOMAIN}${PORT_SUFFIX}"

    set_env "LOG_LEVEL" "warning"
    set_env "LOG_STACK" "daily"
    set_env "LOG_DAILY_DAYS" "14"
    set_env "DEVELOPER_MODE" "false"

    set_env "DB_CONNECTION" "mysql"
    set_env "DB_HOST" "127.0.0.1"
    set_env "DB_PORT" "3306"
    set_env "DB_DATABASE" "$DB_NAME"
    set_env "DB_USERNAME" "$DB_USER"
    set_env "DB_PASSWORD" "$DB_PASSWORD"

    # Keep the session cookie off plain HTTP when serving HTTPS.
    if $SSL_ENABLED; then
        set_env "SESSION_SECURE_COOKIE" "true"
    fi

    set_env "CACHE_STORE" "redis"
    set_env "SESSION_DRIVER" "redis"
    set_env "QUEUE_CONNECTION" "redis"
    set_env "BROADCAST_CONNECTION" "reverb"

    set_env "REDIS_CLIENT" "phpredis"
    set_env "REDIS_HOST" "127.0.0.1"
    set_env "REDIS_PORT" "6379"
    set_env "REDIS_DB" "0"
    set_env "REDIS_CACHE_DB" "1"

    set_env "REVERB_SERVER_HOST" "127.0.0.1"
    set_env "REVERB_SERVER_PORT" "$REVERB_PORT"
    set_env "REVERB_APP_KEY" "$reverb_app_key"
    set_env "REVERB_APP_SECRET" "$reverb_app_secret"
    set_env "REVERB_APP_ID" "$reverb_app_id"
    set_env "REVERB_HOST" "$DOMAIN"
    set_env "REVERB_PORT" "$PUBLIC_PORT"
    set_env "REVERB_SCHEME" "$SCHEME"

    # Vite-prefixed vars (baked into JS bundle at build time)
    set_env "VITE_REVERB_APP_KEY" "$reverb_app_key"
    set_env "VITE_REVERB_HOST" "$DOMAIN"
    set_env "VITE_REVERB_PORT" "$PUBLIC_PORT"
    set_env "VITE_REVERB_SCHEME" "$SCHEME"

    # Demo mode
    if $DEMO_MODE; then
        set_env "DEMO_MODE" "true"
        set_env "DB_CONNECTION" "demo"
        set_env "DEMO_DB_DATABASE" "$DEMO_DB_NAME"
        set_env "DEMO_DB_USERNAME" "$DEMO_DB_USER"
        set_env "DEMO_DB_PASSWORD" "$DEMO_DB_PASSWORD"
        # Sessions and cache must live on the filesystem — the DB connection is swapped
        # per-request by DemoMiddleware, so database-backed sessions/cache would be lost
        # after each connection switch.
        set_env "SESSION_DRIVER" "file"
        set_env "CACHE_STORE" "file"
        set_env "QUEUE_CONNECTION" "sync"
    fi

    # Octane / FrankenPHP
    set_env "OCTANE_SERVER" "frankenphp"
    set_env "OCTANE_WORKERS" "auto"
    set_env "OCTANE_MAX_REQUESTS" "500"
    set_env "DOMAIN" "${DOMAIN}${PORT_SUFFIX}"
    set_env "CADDY_SITE_ADDRESS" "${SCHEME}://${DOMAIN}${PORT_SUFFIX}"
    set_env "APP_PATH" "$APP_PATH"

    # Root-owned and group-readable: the app reads it but cannot change it.
    chown "root:${WEB_GROUP}" "$env_file"
    chmod 640 "$env_file"

    log_info ".env configured for production"
}

# --- Ownership ---
# The code (including deploy.sh, update.sh, backup.sh and restore.sh, which
# run as root) stays root-owned, so the fdcommander user cannot change what
# root later runs. fdcommander may write only the runtime directories and the
# build output that composer and npm, run as fdcommander, produce. Nothing
# run as root loads code from those build directories. update.sh carries the
# same function.
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

# Git as root against the root-owned checkout. A checkout an older deploy
# left owned by fdcommander is used as that user until it is taken over, so
# root never runs hooks or config the app user could have written.
app_git() {
    if [[ "$(stat -c '%U' "$APP_PATH/.git")" == "root" ]]; then
        git -C "$APP_PATH" "$@"
    else
        sudo -u fdcommander git -C "$APP_PATH" "$@"
    fi
}

setup_app() {
    log_phase "Phase 3: Setting up application"

    # Step 1: Clone or copy app
    if [[ -n "$REPO_URL" ]]; then
        if [[ -d "$APP_PATH/.git" ]]; then
            log_warn "App directory already contains a git repo, pulling latest..."
            app_git fetch origin
            app_git checkout "$BRANCH"
            app_git pull origin "$BRANCH"
        else
            log_info "Cloning repository..."
            git clone --branch "$BRANCH" "$REPO_URL" "$APP_PATH"
        fi
    else
        if [[ -d "$APP_PATH/artisan" ]] || [[ -f "$APP_PATH/artisan" ]]; then
            log_warn "App already exists at $APP_PATH, skipping copy"
        else
            log_info "Copying application to $APP_PATH..."
            mkdir -p "$APP_PATH"
            rsync -a --exclude='.git' --exclude='node_modules' --exclude='vendor' --exclude='.env' \
                "$(pwd)/" "$APP_PATH/"
        fi
    fi

    # Step 2: Set ownership
    apply_app_permissions

    # Step 3: Generate .env
    configure_env

    # Extra hostnames, also on a re-run that keeps the existing .env.
    if [[ -n "$TRUSTED_HOSTS" ]]; then
        set_env "TRUSTED_HOSTS" "$TRUSTED_HOSTS"
        log_info "Trusted hosts: ${TRUSTED_HOSTS}"
    fi

    # Only when asked: the app's default trusts forwarded headers from this
    # machine alone, since FrankenPHP faces browsers directly.
    if [[ -n "$TRUSTED_PROXIES" ]]; then
        set_env "TRUSTED_PROXIES" "$TRUSTED_PROXIES"
        log_info "Trusted proxies: ${TRUSTED_PROXIES}"
    fi

    # Generate the app key here rather than with key:generate, which would
    # have to run as root (the app user cannot write .env). Never replace an
    # existing key: two-factor secrets are encrypted with it.
    if ! grep -qE '^APP_KEY=.+' "$APP_PATH/.env"; then
        log_info "Generating application key..."
        set_env "APP_KEY" "base64:$(head -c 32 /dev/urandom | base64)"
    fi

    # Step 4: Install dependencies
    log_info "Installing Composer dependencies..."
    cd "$APP_PATH"
    sudo -u fdcommander ${APP_TMP_ENV[@]+"${APP_TMP_ENV[@]}"} COMPOSER_HOME="$APP_PATH/.composer" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

    # Step 5: Install Node dependencies and build
    log_info "Installing Node dependencies and building assets..."
    cd "$APP_PATH"
    sudo -u fdcommander ${APP_TMP_ENV[@]+"${APP_TMP_ENV[@]}"} HOME="$APP_PATH" NO_UPDATE_NOTIFIER=1 npm ci --ignore-scripts --cache "$APP_PATH/.npm"
    sudo -u fdcommander ${APP_TMP_ENV[@]+"${APP_TMP_ENV[@]}"} HOME="$APP_PATH" NO_UPDATE_NOTIFIER=1 npm run build

    # Explicit modes on what the builds created, whatever umask the app
    # user's session had (STIG profiles set 077).
    apply_app_permissions

    # Step 6: Storage link (what storage:link creates; public/ is root-owned)
    if [[ ! -L "$APP_PATH/public/storage" ]]; then
        ln -s "$APP_PATH/storage/app/public" "$APP_PATH/public/storage"
    else
        log_warn "Storage link already exists, skipping"
    fi

    # Step 7: Operator scripts run as root; older tags may not ship them.
    for script in deploy.sh update.sh backup.sh restore.sh; do
        if [[ -f "$APP_PATH/$script" ]]; then
            chmod 755 "$APP_PATH/$script"
        fi
    done

    log_info "Application setup complete"
}

setup_database() {
    log_phase "Phase 4b: Setting up database"

    # Start and enable database service
    systemctl enable "$DB_SERVICE"
    systemctl start "$DB_SERVICE"

    # Create database and user. DB_NAME/DB_USER are validated as [A-Za-z0-9_];
    # escape backslashes, then single quotes, in the password for SQL.
    local safe_password="${DB_PASSWORD//\\/\\\\}"
    safe_password="${safe_password//\'/\'\'}"
    log_info "Creating database and user..."
    mysql -u root <<SQLEOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${safe_password}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${safe_password}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQLEOF

    log_info "Database and user created"

    # Demo mode: create demo_provisioner user and demo_base database
    if $DEMO_MODE; then
        local safe_demo_password="${DEMO_DB_PASSWORD//\'/\'\'}"
        log_info "Creating demo provisioner database and user..."
        mysql -u root <<DEMOEOF
CREATE DATABASE IF NOT EXISTS \`${DEMO_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DEMO_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${safe_demo_password}';
CREATE USER IF NOT EXISTS '${DEMO_DB_USER}'@'localhost' IDENTIFIED BY '${safe_demo_password}';
GRANT ALL PRIVILEGES ON \`demo\\_%\`.* TO '${DEMO_DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`demo\\_%\`.* TO '${DEMO_DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DEMO_DB_NAME}\`.* TO '${DEMO_DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DEMO_DB_NAME}\`.* TO '${DEMO_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
DEMOEOF
        log_info "Demo provisioner user and database created"
    fi

    # Run migrations
    log_info "Running migrations..."
    cd "$APP_PATH"
    sudo -u fdcommander php artisan migrate --force

    # In demo mode the default connection is 'demo' (demo_base), so also
    # migrate the main 'mysql' connection for analytics tables (demo_sessions,
    # demo_events) that live in the primary database.
    if $DEMO_MODE; then
        log_info "Running migrations on main database (analytics tables)..."
        sudo -u fdcommander php artisan migrate --database=mysql --force
    fi

    # Run production seeders
    if ! $NO_SEEDERS; then
        log_info "Running production seeders..."
        local seeders=(
            "EventTypeSeeder"
            "BandSeeder"
            "ModeSeeder"
            "SectionSeeder"
            "OperatingClassSeeder"
            "BonusTypeSeeder"
            "PermissionSeeder"
            "RoleSeeder"
            "SystemAdminSeeder"
        )
        for seeder in "${seeders[@]}"; do
            log_info "  Seeding: $seeder"
            sudo -u fdcommander php artisan db:seed --class="$seeder" --force
        done
    else
        log_warn "Seeders skipped (--no-seeders)"
    fi

    log_info "Database setup complete"
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
    return 0
}

configure_redis() {
    log_phase "Phase 4a: Configuring Redis"

    local conf_file
    if [[ "$DISTRO_FAMILY" == "$DISTRO_DEBIAN" ]]; then
        conf_file="/etc/redis/redis.conf"
    else
        conf_file="/etc/redis/redis.conf"
        [[ -f "/etc/redis.conf" ]] && conf_file="/etc/redis.conf"
    fi

    if [[ ! -f "$conf_file" ]]; then
        log_error "Redis config not found at ${conf_file}"
        exit 1
    fi

    local redis_maxmemory
    redis_maxmemory=$(compute_redis_maxmemory)
    log_info "Redis maxmemory sized for host: ${redis_maxmemory}"

    # Drop-in overrides tuned for Field Day Commander:
    #  - AOF + appendfsync always  → every Redis write fsyncs to disk, so queue
    #    jobs survive a power cut. (Contacts themselves go straight to the DB,
    #    not through Redis — DB durability covers them.)
    #  - maxmemory + volatile-lru  → heavy RAM caching sized to the host; only
    #    TTL'd cache/session keys are eligible for eviction, so queue jobs
    #    without a TTL stay put.
    local override="/etc/redis/fdcommander.conf"
    log_info "Writing Redis overrides to ${override}..."
    mkdir -p "$(dirname "$override")"
    cat > "$override" <<REDISEOF
# Field Day Commander Redis tuning — durable queue + heavy RAM cache.
appendonly yes
appendfsync always
no-appendfsync-on-rewrite no
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
aof-use-rdb-preamble yes

# Keep RDB snapshots as a secondary safety net.
save 900 1
save 300 10
save 60 10000

# Heavy RAM caching, sized to this host by deploy.sh.
maxmemory ${redis_maxmemory}
maxmemory-policy volatile-lru

# Persistent connections from phpredis benefit from a modest TCP keepalive.
tcp-keepalive 60
REDISEOF

    # Include the override from the main redis.conf (idempotent).
    if ! grep -q "^include ${override}" "$conf_file"; then
        echo "" >> "$conf_file"
        echo "# Field Day Commander overrides" >> "$conf_file"
        echo "include ${override}" >> "$conf_file"
    fi

    systemctl enable "$REDIS_SERVICE"
    systemctl restart "$REDIS_SERVICE"

    # Verify redis is responding
    if ! redis-cli ping | grep -q PONG; then
        log_error "Redis did not respond to PING after restart"
        exit 1
    fi
    log_info "Redis configured with AOF fsync=always and volatile-lru eviction"
    return 0
}

configure_caddy() {
    log_phase "Phase 5: Configuring FrankenPHP/Caddy"

    local caddyfile="${APP_PATH}/Caddyfile"
    if [[ ! -f "$caddyfile" ]]; then
        log_error "Caddyfile not found at ${caddyfile}"
        exit 1
    fi

    log_info "Caddyfile found at ${caddyfile}"

    mkdir -p /var/lib/caddy/.config/caddy
    mkdir -p /var/lib/caddy/.local/share/caddy
    chown -R "fdcommander:${WEB_GROUP}" /var/lib/caddy

    log_info "FrankenPHP/Caddy configured"
}

# Root-owned Caddyfile snippets imported into the site block (see Caddyfile).
CADDY_SNIPPET_DIR="/etc/frankenphp/caddy.d"

# write_caddy_tls_snippet <cert> <key> <email>: TLS settings for an HTTPS
# site. With a cert and key Caddy serves them; otherwise the email is used for
# the ACME (Let's Encrypt) account. HSTS is sent by the app (see
# SECURITY_HSTS_MAX_AGE), not here. update.sh carries the same function.
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

configure_ssl() {
    if ! $SSL_ENABLED; then
        # A re-run without --ssl must not keep serving an old TLS snippet.
        rm -f "${CADDY_SNIPPET_DIR}/tls.caddy"
        log_warn "SSL not enabled — Caddy will serve HTTP only"
        log_warn "To enable HTTPS, re-run with --ssl --email <address> (or --ssl-cert/--ssl-key)"
        return
    fi

    log_phase "Phase 6: Configuring SSL"

    if [[ -n "$SSL_CERT" ]]; then
        # Caddy runs as fdcommander and must be able to read both files.
        local file
        for file in "$SSL_CERT" "$SSL_KEY"; do
            if path_in_private_tmp "$file"; then
                log_error "${file} is under /tmp or /var/tmp, which the Field Day Commander services cannot see."
                log_error "Move it somewhere permanent (e.g. /etc/ssl/fd-commander/) and re-run."
                exit 1
            fi
            if ! sudo -u fdcommander test -r "$file"; then
                log_error "fdcommander cannot read ${file}. Make it readable by group ${WEB_GROUP}"
                log_error "(e.g. chgrp ${WEB_GROUP} ${file} && chmod 640 ${file}) and re-run."
                exit 1
            fi
        done
        log_info "Using custom certificate ${SSL_CERT} (key ${SSL_KEY})"
        log_info "Renewing the certificate is up to you; restart fdcommander.service after replacing it"
        # HSTS stays as it is: with a self-signed certificate it would lock
        # browsers out. Set SECURITY_HSTS_MAX_AGE in .env if browsers trust it.
    else
        log_info "Caddy will automatically obtain and renew Let's Encrypt certificates (account ${SSL_EMAIL})"
        log_info "Ensure DNS for ${DOMAIN} points to this server"
        log_info "Caddy handles ACME challenges automatically — no certbot needed"
        # A certificate browsers trust, renewed automatically: safe for HSTS.
        set_env "SECURITY_HSTS_MAX_AGE" "31536000"
        log_info "HSTS enabled (SECURITY_HSTS_MAX_AGE=31536000)"
    fi
    write_caddy_tls_snippet "$SSL_CERT" "$SSL_KEY" "$SSL_EMAIL"
    log_info "Caddy TLS settings written to ${CADDY_SNIPPET_DIR}/tls.caddy"
}

# write_unit_hardening: sandbox every Field Day Commander unit with a drop-in, so
# units written by older deploys get it too (update.sh carries the same
# function). The services may write only storage/ and bootstrap/cache (plus
# Caddy's certificate store for the web server).
write_unit_hardening() {
    local protect_home="true" unit dir extra_paths
    # An install or certificate under /home or /root must stay readable.
    if [[ "$APP_PATH" == /home/* || "$APP_PATH" == /root/* ]] \
        || grep -sqE '"/(home|root)/' "${CADDY_SNIPPET_DIR}"/*.caddy; then
        protect_home="read-only"
    fi
    for unit in fdcommander fdcommander-queue fdcommander-scheduler fdcommander-reverb; do
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
    return 0
}

configure_systemd() {
    log_phase "Phase 7: Configuring systemd services"

    # FrankenPHP/Octane Web Server
    #
    # Worker count is sized to the host: nproc workers, with a floor of 2 so
    # even a single-core box can handle a polling request alongside a user
    # request. Each Octane FrankenPHP worker is a thread inside one process,
    # so per-worker memory cost is small.
    #
    # Octane is started with the PHP CLI, not `frankenphp php-cli`: since
    # FrankenPHP 1.13, php-cli puts the script path in $argv[1], which artisan
    # misreads as the command name. Octane still runs the server through the
    # frankenphp binary on PATH.
    local octane_workers
    octane_workers=$(compute_octane_workers)
    log_info "Creating FrankenPHP/Octane service with ${octane_workers} workers..."
    cat > /etc/systemd/system/fdcommander.service <<WEBEOF
[Unit]
Description=Field Day Commander Web Server (FrankenPHP/Octane)
After=network.target ${DB_SERVICE}.service ${REDIS_SERVICE}.service

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
EnvironmentFile=${APP_PATH}/.env
ExecStart=/usr/bin/php artisan octane:frankenphp --host=0.0.0.0 --port=${APP_PORT} --workers=${octane_workers} --caddyfile=${APP_PATH}/Caddyfile
Restart=always
RestartSec=5
$( [[ "$APP_PORT" -lt 1024 ]] && printf 'CapabilityBoundingSet=CAP_NET_BIND_SERVICE\nAmbientCapabilities=CAP_NET_BIND_SERVICE' || echo '# No privileged port capabilities needed' )
Environment=XDG_CONFIG_HOME=/var/lib/caddy/.config
Environment=XDG_DATA_HOME=/var/lib/caddy/.local/share
Environment=PHPRC=/etc/frankenphp

[Install]
WantedBy=multi-user.target
WEBEOF

    # Queue Worker
    log_info "Creating queue worker service..."
    cat > /etc/systemd/system/fdcommander-queue.service <<QUEUEEOF
[Unit]
Description=Field Day Commander Queue Worker
After=network.target ${DB_SERVICE}.service ${REDIS_SERVICE}.service

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
QUEUEEOF

    # Scheduler (long-running schedule:work)
    #
    # Must stay running: external-logger:monitor restarts crashed listeners
    # as detached children, and they live in this unit's cgroup. A oneshot
    # unit's cgroup is killed as soon as schedule:run exits, taking any
    # listener it just restarted with it.
    log_info "Creating scheduler service..."
    if [[ -f /etc/systemd/system/fdcommander-scheduler.timer ]]; then
        systemctl disable --now fdcommander-scheduler.timer || true
        rm -f /etc/systemd/system/fdcommander-scheduler.timer
    fi
    cat > /etc/systemd/system/fdcommander-scheduler.service <<SCHEDEOF
[Unit]
Description=Field Day Commander Task Scheduler
After=network.target ${DB_SERVICE}.service ${REDIS_SERVICE}.service

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
ExecStart=/usr/bin/php artisan schedule:work --no-interaction
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SCHEDEOF

    # Reverb WebSocket server
    log_info "Creating Reverb WebSocket service..."
    cat > /etc/systemd/system/fdcommander-reverb.service <<REVERBEOF
[Unit]
Description=Field Day Commander Reverb WebSocket Server
After=network.target

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=${REVERB_PORT}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
REVERBEOF

    write_unit_hardening

    # Last .env write is behind us (configure_ssl), and systemd reads it
    # before starting the web server.
    selinux_label_app

    # Reload and enable
    systemctl daemon-reload
    systemctl enable --now fdcommander.service
    systemctl enable --now fdcommander-queue.service
    systemctl enable --now fdcommander-scheduler.service
    systemctl enable --now fdcommander-reverb.service

    log_info "All systemd services enabled and started"
}

configure_firewall() {
    log_phase "Phase 8: Configuring firewall"

    if [[ "$DISTRO_FAMILY" == "$DISTRO_DEBIAN" ]]; then
        if command -v ufw &>/dev/null && ufw status | grep -q "active"; then
            log_info "Configuring UFW firewall..."
            ufw allow "${APP_PORT}/tcp"
            log_info "UFW configured: port ${APP_PORT} open"
        else
            log_warn "UFW not active. Recommend enabling: ufw allow ${APP_PORT}/tcp && ufw enable"
        fi
    elif [[ "$DISTRO_FAMILY" == "$DISTRO_RHEL" ]]; then
        if systemctl is-active --quiet firewalld; then
            log_info "Configuring firewalld..."
            # Only the web port: Reverb listens on 127.0.0.1 behind Caddy.
            local -a rule=(--add-port="${APP_PORT}/tcp") query=(--query-port="${APP_PORT}/tcp")
            if [[ "$APP_PORT" == "80" ]]; then
                rule=(--add-service=http) query=(--query-service=http)
            elif [[ "$APP_PORT" == "443" ]]; then
                rule=(--add-service=https) query=(--query-service=https)
            fi
            if firewall-cmd --permanent "${query[@]}" &>/dev/null; then
                log_info "firewalld: port ${APP_PORT} is already open"
            else
                firewall-cmd --permanent "${rule[@]}"
                firewall-cmd --reload
                log_info "firewalld configured: port ${APP_PORT} open"
            fi
        else
            log_warn "firewalld not active. Recommend enabling and opening port ${APP_PORT}"
        fi
    fi
}

finalize() {
    log_phase "Phase 9: Caching and finalizing"

    cd "$APP_PATH"

    log_info "Optimizing application (config/route/view/event caches)..."
    sudo -u fdcommander php artisan optimize
    apply_app_permissions

    if ! verify_deploy; then
        log_error "Field Day Commander was installed but is not running (see above)."
        log_error "Fix the cause and re-run deploy.sh with the same options. Deploy log: ${LOG_FILE}"
        exit 1
    fi
    report_certificate

    # Print summary
    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}  Field Day Commander Deployment Complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    echo -e "${BOLD}Application${NC}"
    echo "  URL:            ${SCHEME}://${DOMAIN}${PORT_SUFFIX}"
    echo "  Path:           ${APP_PATH}"
    echo "  Environment:    production"
    echo ""
    echo -e "${BOLD}Database${NC}"
    echo "  Host:           127.0.0.1"
    echo "  Name:           ${DB_NAME}"
    echo "  User:           ${DB_USER}"
    if $DB_PASSWORD_GENERATED; then
        # Not printed: this output is also written to the deploy log.
        echo "  Password:       auto-generated, stored as DB_PASSWORD in ${APP_PATH}/.env"
    fi
    echo ""
    echo -e "${BOLD}Services${NC}"
    echo "  Queue Worker:   $(systemctl is-active fdcommander-queue.service)"
    echo "  Scheduler:      $(systemctl is-active fdcommander-scheduler.service)"
    echo "  Reverb:         $(systemctl is-active fdcommander-reverb.service)"
    echo "  FrankenPHP:     $(systemctl is-active fdcommander.service)"
    echo "  Redis:          $(systemctl is-active ${REDIS_SERVICE}.service)"
    echo ""
    echo -e "${BOLD}Logs${NC}"
    echo "  Deploy log:     ${LOG_FILE}"
    echo "  App log:        ${APP_PATH}/storage/logs/laravel-YYYY-MM-DD.log (kept 14 days)"
    echo "  Caddy log:      journalctl -u fdcommander.service"
    echo ""
    if $DEMO_MODE; then
        echo -e "${BOLD}Demo Mode${NC}"
        echo "  Provisioner:    ${DEMO_DB_USER}"
        echo "  Provisioner PW: ${DEMO_DB_PASSWORD}"
        echo "  Base Database:  ${DEMO_DB_NAME}"
        echo "  Max Sessions:   25 (configurable via DEMO_MAX_SESSIONS)"
        echo "  Session TTL:    24 hours (configurable via DEMO_TTL_HOURS)"
        echo -e "  ${YELLOW}(save the provisioner password — it won't be shown again)${NC}"
        echo ""
    fi

    echo -e "${BOLD}Next Steps${NC}"
    if $DEMO_MODE; then
        echo "  1. Visit ${SCHEME}://${DOMAIN}${PORT_SUFFIX} — visitors see a role-picker landing page"
        echo "  2. Each visitor gets an isolated sandbox database automatically"
        echo "  3. Expired sessions are cleaned up hourly by the scheduler"
        echo "  4. Live contact simulation runs every minute"
        if ! $SSL_ENABLED; then
            echo "  5. For HTTPS: re-run with --ssl --email <address> and a real domain name"
        fi
    else
        echo "  1. Visit ${SCHEME}://${DOMAIN}${PORT_SUFFIX} now: the setup wizard sets the"
        echo "     SYSTEM admin password (anyone who reaches it first can complete it)"
        echo "  2. Then sign in with callsign SYSTEM and the password you chose"
        echo "  3. Review firewall settings if not configured"
        echo "  4. Schedule backups: sudo ${APP_PATH}/backup.sh --install-schedule"
        if ! $SSL_ENABLED; then
            echo "  5. For HTTPS: re-run with --ssl --email <address> and a real domain name"
        fi
    fi
    echo ""
}

# --- Main entrypoint ---
main() {
    validate_args
    detect_distro

    if $DRY_RUN; then
        print_dry_run
        exit 0
    fi

    log_phase "Starting Field Day Commander deployment to ${SCHEME}://${DOMAIN}"
    DEPLOY_STARTED=$(date '+%Y-%m-%d %H:%M:%S')
    trap cleanup_work_dir EXIT

    install_packages
    setup_app
    # Redis first: migrations clear the cache, which is Redis. apt starts it
    # on install, but dnf does not, so a RHEL-family install needs this order.
    configure_redis
    setup_database
    configure_caddy
    configure_ssl
    configure_systemd
    configure_firewall
    finalize
}

main
