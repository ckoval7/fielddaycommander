#!/usr/bin/env bash
set -euo pipefail

# --- Constants ---
SCRIPT_VERSION="1.0.0"
LOG_FILE="/var/log/fd-commander-deploy.log"

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# --- Logging ---
log_info()    { echo -e "${GREEN}[INFO]${NC} $1" | tee -a "$LOG_FILE"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $1" | tee -a "$LOG_FILE"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"; }
log_phase()   { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}" | tee -a "$LOG_FILE"; }

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
SSL_ENABLED=false
SSL_EMAIL=""
SSL_CERT=""
SSL_KEY=""
NO_SEEDERS=false
DRY_RUN=false

# Save original args for potential re-exec (password sanitization)
ORIG_ARGS=("$@")

usage() {
    cat <<'USAGE'
Usage: deploy.sh --domain <domain> [options]

Required:
  --domain <domain>       Domain name or IP for the application

Optional:
  --db-password <pass>    MySQL password (default: randomly generated)
  --db-name <name>        Database name (default: fd_commander)
  --db-user <user>        Database user (default: fd_commander)
  --app-path <path>       Install path (default: /var/www/fd-commander)
  --branch <branch>       Git branch to deploy (default: main)
  --repo-url <url>        Git repo URL (default: copy current directory)
  --reverb-port <port>    Reverb WebSocket port (default: 8080)
  --ssl                   Enable HTTPS via Let's Encrypt
  --email <email>         Email for Let's Encrypt (required with --ssl)
  --ssl-cert <path>       Path to existing SSL certificate
  --ssl-key <path>        Path to existing SSL key
  --no-seeders            Skip database seeders
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
        --ssl)          SSL_ENABLED=true; shift ;;
        --email)        SSL_EMAIL="$2"; shift 2 ;;
        --ssl-cert)     SSL_CERT="$2"; shift 2 ;;
        --ssl-key)      SSL_KEY="$2"; shift 2 ;;
        --no-seeders)   NO_SEEDERS=true; shift ;;
        --dry-run)      DRY_RUN=true; shift ;;
        -h|--help)      usage 0 ;;
        *)              log_error "Unknown option: $1"; usage 1 ;;
    esac
done

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

    if [[ "$ID" == "ubuntu" ]] || [[ "$ID" == "debian" ]]; then
        DISTRO_FAMILY="debian"
        PKG_MANAGER="apt"
        WEB_GROUP="www-data"
        FPM_POOL_DIR="/etc/php/8.4/fpm/pool.d"
        FPM_SOCKET="/run/php/fdcommander-fpm.sock"
        FPM_SERVICE="php8.4-fpm"
        NGINX_SITES_DIR="/etc/nginx/sites-available"
        NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"
    elif [[ "$ID" == "rhel" ]] || [[ "$ID" == "almalinux" ]] || [[ "$ID" == "rocky" ]] || [[ "${ID_LIKE:-}" == *"rhel"* ]] || [[ "${ID_LIKE:-}" == *"fedora"* ]]; then
        DISTRO_FAMILY="rhel"
        PKG_MANAGER="dnf"
        WEB_GROUP="nginx"
        FPM_POOL_DIR="/etc/php-fpm.d"
        FPM_SOCKET="/run/php-fpm/fdcommander.sock"
        FPM_SERVICE="php-fpm"
        NGINX_SITES_DIR="/etc/nginx/conf.d"
        NGINX_ENABLED_DIR=""  # RHEL uses conf.d directly
    else
        log_error "Unsupported distribution: $ID"
        exit 1
    fi

    log_info "Detected distro family: ${DISTRO_FAMILY} ($PRETTY_NAME)"
}

# --- Secret Generation ---
generate_secret() {
    local length="${1:-32}"
    tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length"
}

# Generate DB password if not provided
if [[ -z "$DB_PASSWORD" ]]; then
    DB_PASSWORD=$(generate_secret 32)
    DB_PASSWORD_GENERATED=true
else
    DB_PASSWORD_GENERATED=false
fi

# --- Derived Values ---
if $SSL_ENABLED; then
    SCHEME="https"
    PUBLIC_PORT="443"
else
    SCHEME="http"
    PUBLIC_PORT="80"
fi

# --- Root Check ---
check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root (or via sudo)"
        exit 1
    fi
}

# --- Dry Run ---
print_dry_run() {
    echo -e "\n${BOLD}FD Commander Deployment Plan${NC}"
    echo "=============================="
    echo "Domain:         $DOMAIN"
    echo "App URL:        ${SCHEME}://${DOMAIN}"
    echo "App Path:       $APP_PATH"
    echo "Distro:         $DISTRO_FAMILY ($PRETTY_NAME)"
    echo "Database:       $DB_NAME (user: $DB_USER)"
    echo "Reverb Port:    $REVERB_PORT (internal, proxied via Nginx)"
    echo "SSL:            $( $SSL_ENABLED && echo "Yes" || echo "No" )"
    if $SSL_ENABLED; then
        if [[ -n "$SSL_CERT" ]]; then
            echo "SSL Method:     Custom certificate"
        else
            echo "SSL Method:     Let's Encrypt (email: $SSL_EMAIL)"
        fi
    fi
    echo "Source:         $( [[ -n "$REPO_URL" ]] && echo "$REPO_URL (branch: $BRANCH)" || echo "Copy current directory" )"
    echo "Seeders:        $( $NO_SEEDERS && echo "Skipped" || echo "Production seeders" )"
    echo ""
    echo "Phases: Packages → App Setup → Database → Nginx → SSL → Systemd → Firewall → Cache"
}

# --- Package Installation ---
install_packages_debian() {
    log_phase "Phase 2: Installing system packages (Debian/Ubuntu)"

    # Add PHP 8.4 PPA
    if ! command -v php8.4 &>/dev/null; then
        log_info "Adding Ondrej PHP PPA..."
        apt-get update -y
        apt-get install -y software-properties-common
        add-apt-repository -y ppa:ondrej/php
    else
        log_warn "PHP 8.4 already installed, skipping PPA"
    fi

    # Add NodeSource repo
    if ! command -v node &>/dev/null; then
        log_info "Adding NodeSource repo for Node 20..."
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    else
        log_warn "Node.js already installed, skipping NodeSource"
    fi

    log_info "Installing packages..."
    apt-get update -y
    apt-get install -y \
        php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \
        php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl php8.4-redis \
        mysql-server nginx nodejs unzip git

    # Certbot (if SSL with Let's Encrypt)
    if $SSL_ENABLED && [[ -z "$SSL_CERT" ]]; then
        log_info "Installing Certbot..."
        apt-get install -y certbot python3-certbot-nginx
    fi
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
    if ! command -v node &>/dev/null; then
        log_info "Adding NodeSource repo for Node 20..."
        curl -fsSL https://rpm.nodesource.com/setup_20.x | bash -
    else
        log_warn "Node.js already installed, skipping NodeSource"
    fi

    # MySQL 8 community repo
    if ! command -v mysqld &>/dev/null; then
        log_info "Adding MySQL community repo..."
        dnf install -y https://dev.mysql.com/get/mysql80-community-release-el$(rpm -E %rhel)-1.noarch.rpm || true
    fi

    log_info "Installing packages..."
    dnf install -y \
        php-cli php-fpm php-mysqlnd php-mbstring php-xml \
        php-curl php-zip php-bcmath php-gd php-intl php-redis \
        mysql-community-server nginx nodejs unzip git

    # Certbot
    if $SSL_ENABLED && [[ -z "$SSL_CERT" ]]; then
        log_info "Installing Certbot..."
        dnf install -y certbot python3-certbot-nginx
    fi

    # SELinux: allow Nginx to connect to PHP-FPM socket and Reverb upstream
    if command -v getenforce &>/dev/null && [[ "$(getenforce)" != "Disabled" ]]; then
        log_info "Configuring SELinux for Nginx..."
        setsebool -P httpd_can_network_connect 1
    fi
}

install_composer() {
    if command -v composer &>/dev/null; then
        log_warn "Composer already installed, skipping"
        return
    fi

    log_info "Installing Composer..."
    local expected_sig
    expected_sig=$(curl -fsSL https://composer.github.io/installer.sig)
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    local actual_sig
    actual_sig=$(php -r "echo hash_file('sha384', 'composer-setup.php');")

    if [[ "$expected_sig" != "$actual_sig" ]]; then
        log_error "Composer installer signature mismatch"
        rm composer-setup.php
        exit 1
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php
    log_info "Composer installed successfully"
}

create_system_user() {
    if id "fdcommander" &>/dev/null; then
        log_warn "User 'fdcommander' already exists, skipping"
        return
    fi

    log_info "Creating system user 'fdcommander'..."
    useradd --system --no-create-home --shell /usr/sbin/nologin -g "$WEB_GROUP" fdcommander
}

configure_fpm_pool() {
    local pool_file="${FPM_POOL_DIR}/fdcommander.conf"

    log_info "Configuring PHP-FPM pool..."

    # Disable default www pool
    local default_pool="${FPM_POOL_DIR}/www.conf"
    if [[ -f "$default_pool" ]]; then
        mv "$default_pool" "${default_pool}.bak"
        log_info "Disabled default www pool"
    fi

    cat > "$pool_file" <<FPMEOF
[fdcommander]
user = fdcommander
group = ${WEB_GROUP}
listen = ${FPM_SOCKET}
listen.owner = ${WEB_GROUP}
listen.group = ${WEB_GROUP}
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPMEOF

    systemctl enable "$FPM_SERVICE"
    systemctl restart "$FPM_SERVICE"
    log_info "PHP-FPM pool configured and started"
}

install_packages() {
    case "$DISTRO_FAMILY" in
        debian) install_packages_debian ;;
        rhel)   install_packages_rhel ;;
    esac

    install_composer
    create_system_user
    configure_fpm_pool
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

    # Helper to set env values (handles sed metacharacters safely)
    set_env() {
        local key="$1" value="$2"
        # Remove any existing line (commented or not) for this key
        sed -i "/^#\?${key}=/d" "$env_file"
        # Append the new value (avoids sed substitution metacharacter issues)
        echo "${key}=${value}" >> "$env_file"
    }

    set_env "APP_NAME" '"FD Commander"'
    set_env "APP_ENV" "production"
    set_env "APP_DEBUG" "false"
    set_env "APP_URL" "${SCHEME}://${DOMAIN}"

    set_env "LOG_LEVEL" "warning"
    set_env "DEVELOPER_MODE" "false"

    set_env "DB_CONNECTION" "mysql"
    set_env "DB_HOST" "127.0.0.1"
    set_env "DB_PORT" "3306"
    set_env "DB_DATABASE" "$DB_NAME"
    set_env "DB_USERNAME" "$DB_USER"
    set_env "DB_PASSWORD" "$DB_PASSWORD"

    set_env "QUEUE_CONNECTION" "database"
    set_env "BROADCAST_CONNECTION" "reverb"

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

    chown "fdcommander:${WEB_GROUP}" "$env_file"
    chmod 640 "$env_file"

    log_info ".env configured for production"
}

setup_app() {
    log_phase "Phase 3: Setting up application"

    # Step 1: Clone or copy app
    if [[ -n "$REPO_URL" ]]; then
        if [[ -d "$APP_PATH/.git" ]]; then
            log_warn "App directory already contains a git repo, pulling latest..."
            cd "$APP_PATH"
            sudo -u fdcommander git fetch origin
            sudo -u fdcommander git checkout "$BRANCH"
            sudo -u fdcommander git pull origin "$BRANCH"
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
    chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH"

    # Step 3: Generate .env
    configure_env

    # Step 4: Install dependencies
    log_info "Installing Composer dependencies..."
    cd "$APP_PATH"
    sudo -u fdcommander composer install --no-dev --optimize-autoloader --no-interaction

    # Step 5: Generate app key
    log_info "Generating application key..."
    php artisan key:generate --force

    # Step 6: Install Node dependencies and build
    log_info "Installing Node dependencies and building assets..."
    cd "$APP_PATH"
    sudo -u fdcommander npm ci
    sudo -u fdcommander npm run build

    # Step 7: Storage link
    if [[ ! -L "$APP_PATH/public/storage" ]]; then
        php artisan storage:link
    else
        log_warn "Storage link already exists, skipping"
    fi

    # Step 8: Set permissions
    chmod -R 775 "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"
    chown -R "fdcommander:${WEB_GROUP}" "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"

    log_info "Application setup complete"
}

# --- Main entrypoint ---
main() {
    check_root
    validate_args
    detect_distro

    if $DRY_RUN; then
        print_dry_run
        exit 0
    fi

    log_phase "Starting FD Commander deployment to ${SCHEME}://${DOMAIN}"

    install_packages
    setup_app

    # Phase functions will be called here in subsequent tasks
}

main
