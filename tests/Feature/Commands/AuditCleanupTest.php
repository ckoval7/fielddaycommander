<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->freezeSecond();
});

function createAuditLogAt(CarbonInterface $createdAt, bool $isCritical = false, string $action = 'user.login.success'): AuditLog
{
    return AuditLog::forceCreate([
        'action' => $action,
        'ip_address' => '127.0.0.1',
        'is_critical' => $isCritical,
        'created_at' => $createdAt,
    ]);
}

test('deletes non-critical entries older than 365 days by default', function () {
    $old = createAuditLogAt(now()->subDays(366));
    $recent = createAuditLogAt(now()->subDays(364));

    $this->artisan('audit:cleanup')
        ->expectsOutput('Deleted 1 old audit log entries')
        ->expectsOutput('Critical security events were preserved')
        ->assertSuccessful();

    expect(AuditLog::find($old->id))->toBeNull()
        ->and(AuditLog::find($recent->id))->not->toBeNull();
});

test('retains critical entries regardless of age', function () {
    $ancientCritical = createAuditLogAt(now()->subYears(5), isCritical: true, action: 'admin.2fa.bypass_enabled');
    $oldCritical = createAuditLogAt(now()->subDays(400), isCritical: true);
    $oldNormal = createAuditLogAt(now()->subDays(400));

    $this->artisan('audit:cleanup')
        ->expectsOutput('Deleted 1 old audit log entries')
        ->assertSuccessful();

    expect(AuditLog::find($ancientCritical->id))->not->toBeNull()
        ->and(AuditLog::find($oldCritical->id))->not->toBeNull()
        ->and(AuditLog::find($oldNormal->id))->toBeNull();
});

test('keeps entries created exactly at the cutoff', function () {
    $atCutoff = createAuditLogAt(now()->subDays(365));
    $justPastCutoff = createAuditLogAt(now()->subDays(365)->subSecond());

    $this->artisan('audit:cleanup')->assertSuccessful();

    expect(AuditLog::find($atCutoff->id))->not->toBeNull()
        ->and(AuditLog::find($justPastCutoff->id))->toBeNull();
});

test('respects a custom days option', function () {
    $olderThanThirty = createAuditLogAt(now()->subDays(31));
    $withinThirty = createAuditLogAt(now()->subDays(29));
    $criticalOlderThanThirty = createAuditLogAt(now()->subDays(31), isCritical: true);

    $this->artisan('audit:cleanup', ['--days' => 30])
        ->expectsOutput('Deleted 1 old audit log entries')
        ->assertSuccessful();

    expect(AuditLog::find($olderThanThirty->id))->toBeNull()
        ->and(AuditLog::find($withinThirty->id))->not->toBeNull()
        ->and(AuditLog::find($criticalOlderThanThirty->id))->not->toBeNull();
});

test('reports zero when nothing is old enough to delete', function () {
    createAuditLogAt(now()->subDay());

    $this->artisan('audit:cleanup')
        ->expectsOutput('Deleted 0 old audit log entries')
        ->assertSuccessful();

    expect(AuditLog::count())->toBe(1);
});
