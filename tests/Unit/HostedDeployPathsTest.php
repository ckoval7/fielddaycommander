<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Yaml\Yaml;

/*
 * The hosted preset is for a hosting service running one Helm release per
 * club. It sets the club up from a file, keeps SYSTEM for the service's
 * support staff, and keeps a club from getting developer tools, listener
 * ports or a shared database user.
 */

test('the hosted preset sets the club up from a file and keeps SYSTEM for support', function () {
    $values = Yaml::parseFile(base_path('charts/fd-commander/values-hosted.yaml'));

    expect($values['setup']['existingSecret'])->not->toBeEmpty()
        ->and($values['signInLinks']['keySecret'])->not->toBeEmpty()
        ->and($values['app']['systemAccountLocked'])->toBeTrue();
});

test('the hosted preset turns off what a shared cluster cannot give each club', function () {
    $values = Yaml::parseFile(base_path('charts/fd-commander/values-hosted.yaml'));

    expect($values['app']['developerMode'])->toBeFalse()
        ->and($values['externalLoggers']['enabled'])->toBeFalse()
        ->and($values['mysql']['enabled'])->toBeFalse()
        ->and($values['redis']['enabled'])->toBeFalse()
        ->and($values['networkPolicy']['enabled'])->toBeTrue()
        ->and($values['ingress']['tls']['enabled'])->toBeFalse()
        ->and($values['ingress']['annotations']['traefik.ingress.kubernetes.io/router.tls'])->toBe('true');
});
