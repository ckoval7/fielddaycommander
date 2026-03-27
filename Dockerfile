# =============================================================================
# FD Commander Docker Image
# Multi-stage build: composer deps → npm/vite build → production runtime
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1: Install PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---------------------------------------------------------------------------
# Stage 2: Build frontend assets (Vite + Tailwind)
# ---------------------------------------------------------------------------
FROM node:20-alpine AS node

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# Copy source files needed by Vite (blade templates, JS, CSS, config)
COPY . .
# Vendor is needed for Livewire/package asset references during build
COPY --from=composer /app/vendor ./vendor

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: Production runtime
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:latest-php8.4 AS production

LABEL maintainer="FD Commander"
LABEL description="Field Day Commander - Amateur Radio Field Day Logging"

# Install PHP extensions
RUN install-php-extensions \
    pdo_mysql \
    mbstring \
    xml \
    curl \
    zip \
    bcmath \
    gd \
    intl \
    redis \
    pcntl

# Install supervisor
RUN apt-get update && \
    apt-get install -y --no-install-recommends supervisor && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy application code
COPY . .

# Copy built artifacts from previous stages
COPY --from=composer /app/vendor ./vendor
COPY --from=node /app/public/build ./public/build

# Use Docker-specific Caddyfile (plain HTTP, no TLS)
COPY docker/Caddyfile ./Caddyfile

# Copy Docker support files
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Create storage structure and set permissions
RUN mkdir -p storage/{app/public,framework/{cache/data,sessions,views,testing},logs} \
    bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
