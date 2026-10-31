# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# The Sail PHP image plus Chromium and its system libraries, for the Pest
# browser tests. Keep the Playwright version in step with package.json.
#
#   docker build -t fdc-browser-tests tests/Browser
FROM sail-8.4/app

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN npx -y playwright@1.63.0 install --with-deps chromium \
    && chmod -R a+rX /ms-playwright \
    && rm -rf /var/lib/apt/lists/* /root/.npm
