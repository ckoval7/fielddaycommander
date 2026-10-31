#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Runs the browser tests in the fdc-browser-tests image (see Dockerfile).
# Builds the assets first, so the browser never runs stale JavaScript or CSS.
# Extra arguments go to Pest, e.g. `tests/Browser/run.sh --filter=logging`.
set -euo pipefail
cd "$(dirname "$0")/../.."
if [[ -f public/hot ]]; then
  echo "public/hot exists, so pages would load assets from the Vite dev server. Stop it (or delete public/hot) and rerun." >&2
  exit 1
fi
npm run build --silent >/dev/null
docker image inspect fdc-browser-tests >/dev/null 2>&1 || docker build -q -t fdc-browser-tests tests/Browser
exec docker run --rm -v "$PWD":/var/www/html -w /var/www/html -u 1000:1000 \
  -e DEMO_MODE=false -e DB_CONNECTION=sqlite -e HOME=/tmp -e XDEBUG_MODE=off \
  --entrypoint php fdc-browser-tests vendor/bin/pest --compact tests/Browser "$@"
