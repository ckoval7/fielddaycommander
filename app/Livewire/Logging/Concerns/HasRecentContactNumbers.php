<?php

namespace App\Livewire\Logging\Concerns;

use App\Models\Contact;
use Livewire\Attributes\Computed;

/**
 * Numbers the contacts shown in a QSO log's recent list. The using component
 * must expose a `recentContacts` computed property ordered newest first.
 */
trait HasRecentContactNumbers
{
    /**
     * Running QSO numbers (1 = oldest QSO in the log) for the non-deleted
     * contacts shown in the recent list, keyed by contact id.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function recentContactNumbers(): array
    {
        $activeRecent = $this->recentContacts->reject(fn (Contact $contact) => $contact->trashed());
        $olderCount = $this->activeContactCount() - $activeRecent->count();

        return $activeRecent->reverse()
            ->values()
            ->mapWithKeys(fn (Contact $contact, int $index) => [$contact->id => $olderCount + $index + 1])
            ->all();
    }

    /**
     * Total non-deleted contacts in the log the recent list is drawn from.
     */
    abstract protected function activeContactCount(): int;
}
