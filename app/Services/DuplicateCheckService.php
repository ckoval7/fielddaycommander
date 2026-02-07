<?php

namespace App\Services;

use App\Models\Contact;

class DuplicateCheckService
{
    /**
     * Check if a contact would be a duplicate.
     *
     * A contact is duplicate if the same callsign has already been worked
     * on the same band and mode within the same event configuration.
     *
     * @return array{is_duplicate: bool, duplicate_of_contact_id: ?int}
     */
    public function check(string $callsign, int $bandId, int $modeId, int $eventConfigurationId): array
    {
        $existing = Contact::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->where('callsign', strtoupper(trim($callsign)))
            ->where('band_id', $bandId)
            ->where('mode_id', $modeId)
            ->where('is_duplicate', false)
            ->first();

        return [
            'is_duplicate' => $existing !== null,
            'duplicate_of_contact_id' => $existing?->id,
        ];
    }
}
