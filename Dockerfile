# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# =============================================================================
# Field Day Commander Docker Image
# Multi-stage build: composer deps → npm/vite build → production runtime
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1: Install PHP dependencies
# ---------------------------------------------------------------------------
# Build images are pinned to exact releases so a rebuild is reproducible.
# Bump them deliberately (and with the release package updates).
FROM composer:2.10.3 AS composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------------------------------------------------------------------------
# Stage 2: Build frontend assets (Vite + Tailwind)
# ---------------------------------------------------------------------------
FROM node:22.23.3-alpine AS node

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

# Copy source files needed by Vite (blade templates, JS, CSS, config)
COPY . .
# Vendor is needed for Livewire/package asset references during build
COPY --from=composer /app/vendor ./vendor

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: Production runtime
# ---------------------------------------------------------------------------
# Pinned so a FrankenPHP release can't change the runtime under a rebuild.
FROM dunglas/frankenphp:1.13.2-php8.4 AS production

LABEL maintainer="Field Day Commander"
LABEL description="Field Day Commander - Amateur Radio Field Day Logging"

# Install PHP extensions and supervisor. The container runs as www-data on
# unprivileged ports, so FrankenPHP's cap_net_bind_service file capability is
# removed: exec of a binary with a file capability fails outright wherever
# NET_BIND_SERVICE is dropped (e.g. Kubernetes with capabilities: drop ALL).
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
    pcntl \
    sockets \
    && apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && setcap -r /usr/local/bin/frankenphp

WORKDIR /app

# Copy application code
COPY . .

# Copy built artifacts from previous stages
COPY --from=composer /app/vendor ./vendor
COPY --from=node /app/public/build ./public/build

# Run package discovery (needs pdo_mysql available, so must run in production stage)
RUN php artisan package:discover --ansi

# Use Docker-specific Caddyfile (plain HTTP, no TLS)
COPY docker/Caddyfile ./Caddyfile

# Copy Docker support files
COPY docker/php.ini /usr/local/etc/php/conf.d/99-fd-commander.ini
# Replaces the Debian main config so a bare `supervisorctl` finds our socket.
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Set entrypoint permissions and create storage structure. The entrypoint
# truncates .env on boot (settings come from the environment), so it must be
# www-data owned;
# the public/storage symlink is created here because /app/public is not.
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && ln -s /app/storage/app/public public/storage \
    && touch .env \
    && chown -R www-data:www-data storage bootstrap/cache .env /data /config

USER www-data

EXPOSE 8000

# First boot waits for the database and runs migrations/seeders before Octane
# listens, hence the long start period.
HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up > /dev/null || exit 1

# Where this build is published, so `setup` (docker/install.sh) writes a
# compose file pointing back at it. Last, so a new tag only rebuilds this layer.
ARG FDC_IMAGE_REPO=ghcr.io/ckoval7/fielddaycommander
ARG FDC_IMAGE_TAG=""
ENV FDC_IMAGE_REPO=${FDC_IMAGE_REPO} \
    FDC_IMAGE_TAG=${FDC_IMAGE_TAG}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
