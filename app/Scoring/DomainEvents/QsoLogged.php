<?php

namespace App\Scoring\DomainEvents;

use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after the surrounding transaction commits, so bonus
 * reconciliation runs outside the event lock and never for a rolled-back
 * contact. Outside a transaction it dispatches immediately.
 */
final class QsoLogged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Contact $contact,
        public readonly ?int $eventConfigurationId,
    ) {}
}
