#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Runs the browser tests in the fdc-browser-tests image (see Dockerfile).
# Run from the main checkout root after `npm run build`. Extra arguments go to
# Pest, e.g. `tests/Browser/run.sh --filter=logging`.
set -euo pipefail
cd "$(dirname "$0")/../.."
docker image inspect fdc-browser-tests >/dev/null 2>&1 || docker build -q -t fdc-browser-tests tests/Browser
exec docker run --rm -v "$PWD":/var/www/html -w /var/www/html -u 1000:1000 \
  -e DEMO_MODE=false -e DB_CONNECTION=sqlite -e HOME=/tmp -e XDEBUG_MODE=off \
  --entrypoint php fdc-browser-tests vendor/bin/pest --compact tests/Browser "$@"
