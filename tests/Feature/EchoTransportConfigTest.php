<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Regression: pusher-js 8 builds wss:// connections from the transport named
 * 'ws' when forceTLS is set, so enabling only 'wss' on HTTPS left Echo with
 * no usable transport and every connection went straight to "failed".
 * Enabling 'wss' as well would bring back wss:// attempts on HTTP installs.
 */
test('echo enables only the ws transport', function () {
    $bootstrap = file_get_contents(resource_path('js/bootstrap.js'));

    expect($bootstrap)->toMatch("/enabledTransports:\s*\['ws'\]/");
});
