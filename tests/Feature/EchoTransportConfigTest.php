<?php

/**
 * Regression: pusher-js 8 builds wss:// connections from the transport named
 * 'ws' when forceTLS is set, so enabling only 'wss' on HTTPS left Echo with
 * no usable transport and every connection went straight to "failed".
 */
test('echo enables the ws transport so TLS connections can open', function () {
    $bootstrap = file_get_contents(resource_path('js/bootstrap.js'));

    expect($bootstrap)->toMatch("/enabledTransports:\s*\['ws',\s*'wss'\]/");
});
