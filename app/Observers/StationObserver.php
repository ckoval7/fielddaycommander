<?php

namespace App\Observers;

use App\Models\EquipmentEvent;
use App\Models\Station;

/**
 * Observer for Station model to auto-commit primary radios to events.
 *
 * When a station's primary radio (radio_equipment_id) is set, ensures
 * the radio has an equipment_event commitment record so it appears
 * on the Equipment Dashboard.
 */
class StationObserver
{
    /**
     * Handle the Station "created" event.
     */
    public function created(Station $station): void
    {
        $this->syncPrimaryRadioCommitment($station);
    }

    /**
     * Handle the Station "updated" event.
     */
    public function updated(Station $station): void
    {
        if ($station->wasChanged('radio_equipment_id')) {
            $this->handleRadioChange($station);
        }
    }

    /**
     * Handle primary radio change — unassign old radio's station, commit new radio.
     */
    protected function handleRadioChange(Station $station): void
    {
        $oldRadioId = $station->getOriginal('radio_equipment_id');
        $eventId = $station->eventConfiguration?->event_id;

        if (! $eventId) {
            return;
        }

        // Unassign station from old radio's commitment (if it was pointing to this station)
        if ($oldRadioId) {
            EquipmentEvent::query()
                ->where('equipment_id', $oldRadioId)
                ->where('event_id', $eventId)
                ->where('station_id', $station->id)
                ->update(['station_id' => null, 'assigned_by_user_id' => null]);
        }

        $this->syncPrimaryRadioCommitment($station);
    }

    /**
     * Ensure the station's primary radio has an equipment_event record.
     */
    protected function syncPrimaryRadioCommitment(Station $station): void
    {
        if (! $station->radio_equipment_id) {
            return;
        }

        $eventId = $station->eventConfiguration?->event_id;

        if (! $eventId) {
            return;
        }

        $existing = EquipmentEvent::query()
            ->where('equipment_id', $station->radio_equipment_id)
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            // Already committed — just assign the station if not already assigned
            if (! $existing->station_id) {
                $existing->update([
                    'station_id' => $station->id,
                ]);
            }

            return;
        }

        // Create new commitment for this primary radio
        EquipmentEvent::create([
            'equipment_id' => $station->radio_equipment_id,
            'event_id' => $eventId,
            'station_id' => $station->id,
            'status' => 'committed',
            'committed_at' => now(),
            'status_changed_at' => now(),
            'status_changed_by_user_id' => auth()->id(),
        ]);
    }
}
