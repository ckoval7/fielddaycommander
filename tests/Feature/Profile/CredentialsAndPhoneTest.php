<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\Credential;
use App\Livewire\Profile\UserProfile;
use App\Models\User;
use App\Models\UserCredential;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'sam@example.com']);
    $this->actingAs($this->user);
});

test('the profile saves a phone number with a phone keyboard', function () {
    Livewire::test(UserProfile::class)
        ->assertSeeHtml('type="tel"')
        ->assertSeeHtml('autocomplete="tel"')
        ->set('phone', ' 555-0142 ')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($this->user->fresh()->phone)->toBe('555-0142');

    Livewire::test(UserProfile::class)
        ->assertSet('phone', '555-0142')
        ->set('phone', '')
        ->call('saveProfile');

    expect($this->user->fresh()->phone)->toBeNull();
});

test('the profile lists every credential to tick', function () {
    $component = Livewire::test(UserProfile::class)->assertSeeHtml('data-credentials');

    foreach (Credential::cases() as $credential) {
        $component->assertSee($credential->label());
    }
});

test('ticked credentials are saved with level and expiry, unticked ones removed', function () {
    UserCredential::factory()->create(['user_id' => $this->user->id, 'credential' => Credential::Coml]);

    Livewire::test(UserProfile::class)
        ->assertSet('credentials.coml.held', true)
        ->set('credentials.is_100.held', true)
        ->set('credentials.ares.held', true)
        ->set('credentials.ares.level', 'Level 2')
        ->set('credentials.ares.expires_at', '2027-06-30')
        ->set('credentials.coml.held', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $held = $this->user->credentials()->get()->keyBy(fn ($credential) => $credential->credential->value);

    expect($held->keys()->sort()->values()->all())->toBe(['ares', 'is_100'])
        ->and($held['ares']->level)->toBe('Level 2')
        ->and($held['ares']->expires_at->toDateString())->toBe('2027-06-30')
        ->and($held['is_100']->expires_at)->toBeNull();
});

test('only ARES takes a level, and only a listed one', function () {
    Livewire::test(UserProfile::class)
        ->set('credentials.ares.held', true)
        ->set('credentials.ares.level', 'Level 9')
        ->set('credentials.is_100.level', 'Level 1')
        ->call('saveProfile')
        ->assertHasErrors(['credentials.ares.level', 'credentials.is_100.level']);
});

test('expired credentials are flagged on the profile and left off the summary', function () {
    UserCredential::factory()->expired()->create(['user_id' => $this->user->id, 'credential' => Credential::Auxcomm]);
    UserCredential::factory()->create(['user_id' => $this->user->id, 'credential' => Credential::Ares, 'level' => 'Level 1']);
    UserCredential::factory()->create(['user_id' => $this->user->id, 'credential' => Credential::Is700]);

    Livewire::test(UserProfile::class)->assertSee('Expired');

    expect($this->user->fresh()->credentialSummary())->toBe('IS-700, ARES L1');
});
