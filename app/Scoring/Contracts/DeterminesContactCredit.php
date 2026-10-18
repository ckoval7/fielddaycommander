<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Contracts;

use App\Models\Contact;
use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rulebook in which some logged contacts earn no credit at all.
 *
 * WFDA 2027, for example, bans WSJT modes and gives no QSO credit to satellite
 * (relayed, cross-band) contacts. Such contacts are still accepted and logged,
 * but they count toward nothing: QSO points, band or mode objectives, or the
 * submitted log.
 *
 * Rulesets that do not implement this treat every non-duplicate contact as
 * creditable. Callers should not use this contract directly; go through
 * {@see EventConfiguration::creditableContacts()} and
 * {@see EventConfiguration::contactNoCreditReason()}, which apply that fallback.
 *
 * The two methods state the same rules twice — once for a loaded contact, once
 * in SQL — and must always agree.
 */
interface DeterminesContactCredit
{
    /**
     * Why this contact earns no credit, or null when it earns credit.
     *
     * Duplicate status is not considered here; it is a separate concern.
     * Reads the contact's `band` relation, so eager-load it when checking many.
     */
    public function noCreditReason(Contact $contact): ?string;

    /**
     * Constrain a contacts query to contacts that earn credit.
     *
     * The SQL counterpart of {@see noCreditReason()}: a contact survives this
     * constraint exactly when noCreditReason() returns null for it.
     *
     * @param  Builder<Contact>|HasMany<Contact, EventConfiguration>  $query
     */
    public function constrainToCreditable(Builder|HasMany $query): void;
}
