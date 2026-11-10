#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Builds the Windows server installer, dist/field-day-commander-server-setup-<version>.exe,
# from the committed code at HEAD. Runs on Linux with Docker; nothing needs
# Windows. Uncommitted changes are left out, so commit first.
#
#   windows/build-installer.sh
#
# Composer, Node and NSIS run in containers pinned to the same images as the
# Dockerfile. FrankenPHP and WinSW are downloaded and checked against the
# SHA-256 sums below.
set -euo pipefail

# Same FrankenPHP as deploy.sh and the Dockerfile. Its Windows build bundles
# the official PHP for Windows (8.5) with the extension DLLs.
FRANKENPHP_VERSION="1.13.4"
FRANKENPHP_SHA256="22e92d1465437480ca3b6c0618a6f1d2377f7ecd5ae212ba705ed035478d72d1"
# Runs PHP and FrankenPHP as Windows services. The .NET Framework build uses
# the runtime built into Windows 10 and 11.
WINSW_VERSION="2.12.0"
WINSW_SHA256="b5066b7bbdfba1293e5d15cda3caaea88fbeab35bd5b38c41c913d492aadfc4f"

COMPOSER_IMAGE="composer:2.10.3"
NODE_IMAGE="node:22.23.3-alpine"
NSIS_IMAGE="ubuntu:24.04"

repo=$(cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo"

version=$(tr -d '[:space:]' < VERSION)
# Windows wants four numbers: 26.11.1 becomes 26.11.1.0.
case $version in
    [0-9]*.[0-9]*.[0-9]*) numeric="$version.0" ;;
    *) numeric="0.0.0.0" ;;
esac

stage="$repo/build/windows-installer"
downloads="$repo/build/windows-downloads"
out="$repo/dist/field-day-commander-server-setup-$version.exe"
owner="$(id -u):$(id -g)"

log() { printf '==> %s\n' "$*"; }

# download URL FILE SHA256: fetch once, then always check the sum.
download() {
    local url=$1 file=$2 sum=$3
    if [[ ! -f $file ]]; then
        log "Downloading $(basename "$file")"
        curl -fsSL -o "$file.part" "$url"
        mv "$file.part" "$file"
    fi
    if ! echo "$sum  $file" | sha256sum -c --quiet -; then
        echo "Checksum mismatch for $file. Delete it and run again, or check the pinned sum." >&2
        exit 1
    fi
}

rm -rf "$stage"
mkdir -p "$stage" "$downloads" "$repo/dist"

log "Copying the code at $(git rev-parse --short HEAD)"
mkdir -p "$stage/app"
git archive HEAD | tar -x -C "$stage/app"
# Development, other deploy paths and this installer's own sources.
rm -rf "$stage/app"/{tests,docs,charts,docker,windows,.github,.claude} \
    "$stage/app"/{Dockerfile,docker-compose.yml,docker-compose.sail.yml,deploy.sh,update.sh,backup.sh,restore.sh,sonar-project.properties,phpunit.xml,CLAUDE.md}
rm -f "$stage/app"/bootstrap/cache/*.php

log "Installing PHP packages"
docker run --rm -v "$stage/app":/app -w /app --user "$owner" -e COMPOSER_HOME=/tmp/composer "$COMPOSER_IMAGE" \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist --quiet

log "Building the browser assets"
docker run --rm -v "$stage/app":/app -w /app --user "$owner" -e npm_config_cache=/tmp/npm "$NODE_IMAGE" \
    sh -c 'npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && npm run build >/dev/null && rm -rf node_modules'

log "Adding FrankenPHP $FRANKENPHP_VERSION"
download "https://github.com/php/frankenphp/releases/download/v$FRANKENPHP_VERSION/frankenphp-windows-x86_64.zip" \
    "$downloads/frankenphp-$FRANKENPHP_VERSION-windows-x86_64.zip" "$FRANKENPHP_SHA256"
mkdir -p "$stage/frankenphp"
unzip -q "$downloads/frankenphp-$FRANKENPHP_VERSION-windows-x86_64.zip" -d "$stage/frankenphp"
# Headers and libraries for building extensions aren't needed to run it.
rm -rf "$stage/frankenphp"/{dev,lib}

log "Adding WinSW $WINSW_VERSION"
download "https://github.com/winsw/winsw/releases/download/v$WINSW_VERSION/WinSW.NET461.exe" \
    "$downloads/WinSW-$WINSW_VERSION.NET461.exe" "$WINSW_SHA256"
mkdir -p "$stage/services"
cp "$downloads/WinSW-$WINSW_VERSION.NET461.exe" "$stage/services/WinSW.exe"

cp -r windows/server "$stage/server"
cp -r windows/tools "$stage/tools"
cp -r LICENSES "$stage/LICENSES"

log "Building the installer"
docker run --rm -v "$repo":/src -w /src "$NSIS_IMAGE" sh -c '
    set -e
    apt-get update -qq >/dev/null
    apt-get install -y -qq --no-install-recommends nsis >/dev/null
    owner=$1; shift
    makensis "$@"
    chown "$owner" dist/field-day-commander-server-setup-*.exe
' sh "$owner" -V2 -NOCD "-DVERSION=$version" "-DNUMERIC_VERSION=$numeric" \
    "-DSTAGE=build/windows-installer" "-DOUT_FILE=dist/$(basename "$out")" windows/installer/server.nsi

log "Built $out"
