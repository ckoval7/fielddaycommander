<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Scoring\Rules\WinterFieldDay2027;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Restricts a WFD 2027 objective to contacts that earn credit.
 *
 * WSJT-mode and satellite contacts are logged but count toward nothing, so a
 * band or mode they were made on is not "used" for an objective. The rules are
 * read from {@see WinterFieldDay2027} itself rather than the configuration's
 * pinned ruleset, so a 2027 strategy always judges by 2027 rules.
 */
trait CountsCreditableContacts
{
    /**
     * Non-duplicate contacts that earn WFD 2027 credit.
     *
     * @return HasMany<Contact, EventConfiguration>
     */
    protected function creditableContacts(EventConfiguration $config): HasMany
    {
        $query = $config->contacts()->where('is_duplicate', false);

        app(WinterFieldDay2027::class)->constrainToCreditable($query);

        return $query;
    }
}
