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

# --- Early root check (before log file redirect) ---
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
    sudo -u fdcommander COMPOSER_HOME="$APP_PATH/.composer" composer install --no-dev --optimize-autoloader --no-interaction

    # Step 5: Generate app key
    log_info "Generating application key..."
    php artisan key:generate --force

    # Step 6: Install Node dependencies and build
    log_info "Installing Node dependencies and building assets..."
    cd "$APP_PATH"
    sudo -u fdcommander HOME="$APP_PATH" npm ci --cache "$APP_PATH/.npm"
    sudo -u fdcommander HOME="$APP_PATH" npm run build

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

setup_database() {
    log_phase "Phase 4: Setting up database"

    # Start and enable MySQL
    local mysql_service="mysql"
    if [[ "$DISTRO_FAMILY" == "rhel" ]]; then
        mysql_service="mysqld"
    fi

    systemctl enable "$mysql_service"
    systemctl start "$mysql_service"

    # Create database and user (escape single quotes in password for SQL safety)
    local safe_password="${DB_PASSWORD//\'/\'\'}"
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

    # Run migrations
    log_info "Running migrations..."
    cd "$APP_PATH"
    sudo -u fdcommander php artisan migrate --force

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

configure_nginx() {
    log_phase "Phase 5: Configuring Nginx"

    local config_file
    if [[ "$DISTRO_FAMILY" == "debian" ]]; then
        config_file="${NGINX_SITES_DIR}/fd-commander"
    else
        config_file="${NGINX_SITES_DIR}/fd-commander.conf"
    fi

    log_info "Writing Nginx vhost config..."
    cat > "$config_file" <<NGINXEOF
server {
    listen 80;
    server_name ${DOMAIN};
    root ${APP_PATH}/public;

    index index.php;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /app {
        proxy_pass http://127.0.0.1:${REVERB_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 60s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location ~ \.php$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINXEOF

    # Enable site (Debian-specific)
    if [[ "$DISTRO_FAMILY" == "debian" ]]; then
        ln -sf "$config_file" "${NGINX_ENABLED_DIR}/fd-commander"
        # Remove default site if present
        rm -f "${NGINX_ENABLED_DIR}/default"
    fi

    # Test and reload
    nginx -t
    systemctl enable nginx
    systemctl reload nginx

    log_info "Nginx configured and reloaded"
}

configure_ssl() {
    if ! $SSL_ENABLED; then
        log_warn "SSL not enabled, skipping Phase 6"
        return
    fi

    log_phase "Phase 6: Configuring SSL"

    if [[ -n "$SSL_CERT" ]]; then
        # Custom certificate path
        log_info "Configuring Nginx with custom SSL certificate..."

        local config_file
        if [[ "$DISTRO_FAMILY" == "debian" ]]; then
            config_file="${NGINX_SITES_DIR}/fd-commander"
        else
            config_file="${NGINX_SITES_DIR}/fd-commander.conf"
        fi

        # Rewrite config with SSL
        cat > "$config_file" <<SSLEOF
server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    server_name ${DOMAIN};
    root ${APP_PATH}/public;

    ssl_certificate ${SSL_CERT};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    index index.php;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /app {
        proxy_pass http://127.0.0.1:${REVERB_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 60s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location ~ \.php$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
SSLEOF

        nginx -t
        systemctl reload nginx
        log_info "Custom SSL certificate configured"

    else
        # Let's Encrypt path
        log_info "Obtaining Let's Encrypt certificate..."
        certbot --nginx \
            -d "$DOMAIN" \
            --email "$SSL_EMAIL" \
            --agree-tos \
            --non-interactive \
            --redirect

        log_info "Let's Encrypt certificate obtained and auto-renewal configured"
    fi
}

configure_systemd() {
    log_phase "Phase 7: Configuring systemd services"

    # Determine MySQL service name for systemd dependency
    local mysql_unit="mysql.service"
    if [[ "$DISTRO_FAMILY" == "rhel" ]]; then
        mysql_unit="mysqld.service"
    fi

    # Queue Worker
    log_info "Creating queue worker service..."
    cat > /etc/systemd/system/fdcommander-queue.service <<QUEUEEOF
[Unit]
Description=FD Commander Queue Worker
After=network.target ${mysql_unit}

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
QUEUEEOF

    # Scheduler (oneshot + timer)
    log_info "Creating scheduler service and timer..."
    cat > /etc/systemd/system/fdcommander-scheduler.service <<SCHEDEOF
[Unit]
Description=FD Commander Task Scheduler

[Service]
User=fdcommander
Group=${WEB_GROUP}
WorkingDirectory=${APP_PATH}
ExecStart=/usr/bin/php artisan schedule:run --no-interaction
Type=oneshot
SCHEDEOF

    cat > /etc/systemd/system/fdcommander-scheduler.timer <<TIMEREOF
[Unit]
Description=Run FD Commander scheduler every minute

[Timer]
OnCalendar=*:*:00
Persistent=true

[Install]
WantedBy=timers.target
TIMEREOF

    # Reverb WebSocket server
    log_info "Creating Reverb WebSocket service..."
    cat > /etc/systemd/system/fdcommander-reverb.service <<REVERBEOF
[Unit]
Description=FD Commander Reverb WebSocket Server
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

    # Reload and enable
    systemctl daemon-reload
    systemctl enable --now fdcommander-queue.service
    systemctl enable --now fdcommander-scheduler.timer
    systemctl enable --now fdcommander-reverb.service

    log_info "All systemd services enabled and started"
}

configure_firewall() {
    log_phase "Phase 8: Configuring firewall"

    if [[ "$DISTRO_FAMILY" == "debian" ]]; then
        if command -v ufw &>/dev/null && ufw status | grep -q "active"; then
            log_info "Configuring UFW firewall..."
            if $SSL_ENABLED; then
                ufw allow 'Nginx Full'
                log_info "UFW configured: ports 80 and 443 open"
            else
                ufw allow 'Nginx HTTP'
                log_info "UFW configured: port 80 open"
            fi
        else
            log_warn "UFW not active. Recommend enabling: ufw allow 'Nginx Full' && ufw enable"
        fi
    elif [[ "$DISTRO_FAMILY" == "rhel" ]]; then
        if systemctl is-active --quiet firewalld; then
            log_info "Configuring firewalld..."
            firewall-cmd --permanent --add-service=http
            if $SSL_ENABLED; then
                firewall-cmd --permanent --add-service=https
            fi
            firewall-cmd --reload
            log_info "firewalld configured: HTTP/HTTPS open"
        else
            log_warn "firewalld not active. Recommend enabling and opening ports 80/443"
        fi
    fi
}

finalize() {
    log_phase "Phase 9: Caching and finalizing"

    cd "$APP_PATH"

    log_info "Caching configuration..."
    sudo -u fdcommander php artisan config:cache
    sudo -u fdcommander php artisan route:cache
    sudo -u fdcommander php artisan view:cache

    # Print summary
    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}${BOLD}  FD Commander Deployment Complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    echo -e "${BOLD}Application${NC}"
    echo "  URL:            ${SCHEME}://${DOMAIN}"
    echo "  Path:           ${APP_PATH}"
    echo "  Environment:    production"
    echo ""
    echo -e "${BOLD}Database${NC}"
    echo "  Host:           127.0.0.1"
    echo "  Name:           ${DB_NAME}"
    echo "  User:           ${DB_USER}"
    if $DB_PASSWORD_GENERATED; then
        echo "  Password:       ${DB_PASSWORD}"
        echo -e "  ${YELLOW}(auto-generated — save this now, it won't be shown again)${NC}"
    fi
    echo ""
    echo -e "${BOLD}Services${NC}"
    echo "  Queue Worker:   $(systemctl is-active fdcommander-queue.service)"
    echo "  Scheduler:      $(systemctl is-active fdcommander-scheduler.timer)"
    echo "  Reverb:         $(systemctl is-active fdcommander-reverb.service)"
    echo "  Nginx:          $(systemctl is-active nginx)"
    echo "  PHP-FPM:        $(systemctl is-active "$FPM_SERVICE")"
    echo ""
    echo -e "${BOLD}Logs${NC}"
    echo "  Deploy log:     ${LOG_FILE}"
    echo "  App log:        ${APP_PATH}/storage/logs/laravel.log"
    echo "  Nginx log:      /var/log/nginx/"
    echo ""
    echo -e "${BOLD}Next Steps${NC}"
    echo "  1. Visit ${SCHEME}://${DOMAIN} and complete initial setup"
    echo "  2. The SystemAdminSeeder created the first admin user"
    echo "  3. Review firewall settings if not configured"
    if ! $SSL_ENABLED; then
        echo "  4. Consider enabling SSL: re-run with --ssl --email you@example.com"
    fi
    echo ""
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
    setup_database
    configure_nginx
    configure_ssl
    configure_systemd
    configure_firewall
    finalize
}

main
