<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\AdifExporter;

test('JTTY is exported as MFSK with a JTTY submode, as WSJT-X logs it', function () {
    expect((new AdifExporter)->adifMode('JTTY', 'Digital'))->toBe(['MFSK', 'JTTY']);
});
