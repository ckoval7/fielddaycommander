#!/bin/bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Sets up a Docker Compose install from the prebuilt image, no checkout
# needed. Run through the image's entrypoint with the install directory
# mounted at /work:
#
#   docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/work" \
#       ghcr.io/ckoval7/fielddaycommander:latest setup
#
# It writes docker-compose.yml (pointing at this image's tag), backup.sh and
# restore.sh if they are missing, then runs docker/setup.sh to create .env or
# fill in any secrets it lacks. Existing files and values are never replaced,
# so running it again is safe.

set -euo pipefail

WORK_DIR="/work"
IMAGE_REPO="${FDC_IMAGE_REPO:-ghcr.io/ckoval7/fielddaycommander}"
IMAGE_TAG="${FDC_IMAGE_TAG:-$(tr -d '[:space:]' < /app/VERSION)}"

if [[ ! -d "$WORK_DIR" ]]; then
    echo "ERROR: mount the install directory at $WORK_DIR, for example:" >&2
    echo "  docker run --rm -u \"\$(id -u):\$(id -g)\" -v \"\$PWD:/work\" ${IMAGE_REPO}:${IMAGE_TAG} setup" >&2
    exit 1
fi

if [[ ! -w "$WORK_DIR" ]]; then
    echo "ERROR: $WORK_DIR is not writable by uid $(id -u)." >&2
    echo "Run the container as the directory's owner with -u \"\$(id -u):\$(id -g)\"." >&2
    exit 1
fi

# Swaps the source build for this image. The tag goes in as a default, so
# FDC_TAG in .env moves the install to another release without editing the file.
COMPOSE_FILE="$WORK_DIR/docker-compose.yml"
COMPOSE_PART="${COMPOSE_FILE}.part"
if [[ -f "$COMPOSE_FILE" ]]; then
    echo "Keeping existing docker-compose.yml."
else
    sed "s|^    build: \.$|    image: ${IMAGE_REPO}:\${FDC_TAG:-${IMAGE_TAG}}|" \
        /app/docker-compose.yml > "$COMPOSE_PART"
    if ! grep -q "^    image: ${IMAGE_REPO}:" "$COMPOSE_PART"; then
        rm -f "$COMPOSE_PART"
        echo "ERROR: could not point docker-compose.yml at the image." >&2
        exit 1
    fi
    mv "$COMPOSE_PART" "$COMPOSE_FILE"
    echo "Wrote docker-compose.yml for ${IMAGE_REPO}:${IMAGE_TAG}."
fi

for script in backup.sh restore.sh; do
    if [[ ! -f "$WORK_DIR/$script" ]]; then
        install -m 750 "/app/$script" "$WORK_DIR/$script"
        echo "Wrote $script."
    fi
done

echo ""
cd /app
exec bash docker/setup.sh "$WORK_DIR/.env"
