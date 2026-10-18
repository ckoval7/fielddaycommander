<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('callsign is normalized to uppercase when creating a contact', function () {
    $contact = Contact::factory()->create([
        'callsign' => 'w1aw',
    ]);

    expect($contact->callsign)->toBe('W1AW');
});

test('callsign is normalized to uppercase when updating a contact', function () {
    $contact = Contact::factory()->create([
        'callsign' => 'W1AW',
    ]);

    $contact->update(['callsign' => 'k6abc']);

    expect($contact->fresh()->callsign)->toBe('K6ABC');
});

test('gota_operator_callsign is normalized to uppercase', function () {
    $contact = Contact::factory()->create([
        'is_gota_contact' => true,
        'gota_operator_callsign' => 'w1aw',
    ]);

    expect($contact->gota_operator_callsign)->toBe('W1AW');
});

test('gota_operator_callsign is normalized when updating', function () {
    $contact = Contact::factory()->create([
        'is_gota_contact' => true,
        'gota_operator_callsign' => 'W1AW',
    ]);

    $contact->update(['gota_operator_callsign' => 'k6abc']);

    expect($contact->fresh()->gota_operator_callsign)->toBe('K6ABC');
});

test('callsign handles mixed case input', function () {
    $contact = Contact::factory()->create([
        'callsign' => 'W1aW',
    ]);

    expect($contact->callsign)->toBe('W1AW');
});

test('callsign handles null values', function () {
    $contact = Contact::factory()->make();
    $contact->callsign = null;

    expect($contact->callsign)->toBeNull();
});
