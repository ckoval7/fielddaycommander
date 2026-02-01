<?php

it('redirects when accessing home page', function () {
    $response = $this->get('/');

    $response->assertStatus(302);
    // Could redirect to login or setup wizard depending on system state
});
