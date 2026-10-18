<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Station;
use App\Models\User;

class StationPolicy
{
    /**
     * Determine whether the user can view any stations.
     *
     * Users can view the station list if they have the 'view-stations' permission.
     * This permission is granted to Operators, Admins, and Station Captains who need
     * to see configured stations. Operators need to view stations to know which
     * station to log from during field day events.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view-stations');
    }

    /**
     * Determine whether the user can view the station.
     *
     * Users can view individual station details if they have the 'view-stations'
     * permission. This allows authorized users to see station configurations,
     * assigned equipment, operating sessions, and contact logs. Operators need
     * this access to identify which station they're logging from.
     */
    public function view(User $user, Station $station): bool
    {
        return $user->can('view-stations');
    }

    /**
     * Determine whether the user can create stations.
     *
     * Users can create new stations if they have the 'manage-stations' permission.
     * This allows Admins and Station Captains to set up stations for field day
     * events, including configuring GOTA, VHF-only, and satellite stations.
     */
    public function create(User $user): bool
    {
        return $user->can('manage-stations');
    }

    /**
     * Determine whether the user can update the station.
     *
     * Users can update station configurations if they have the 'manage-stations'
     * permission. This includes modifying station properties, power sources,
     * and special designations (GOTA, VHF-only, satellite).
     *
     * Note: When updating stations that have existing contacts, care should be
     * taken with changes that affect scoring (power levels, GOTA status, etc.)
     * as these may require recalculation of field day scores.
     */
    public function update(User $user, Station $station): bool
    {
        return $user->can('manage-stations');
    }

    /**
     * Determine whether the user can change the station's readiness.
     *
     * Station Captains (and anyone else with 'manage-stations') can mark a
     * station unavailable when it cannot be operated (equipment failure,
     * antenna problems, maintenance, etc.) and return it to service afterwards.
     */
    public function updateReadiness(User $user, Station $station): bool
    {
        return $user->can('manage-stations');
    }

    /**
     * Determine whether the user can delete the station.
     *
     * Users can delete stations if they have the 'manage-stations' permission
     * AND the station does not have any active operating sessions.
     *
     * Active stations (with ongoing operating sessions) cannot be deleted to
     * maintain data integrity and prevent loss of active contact logging.
     * Stations must be deactivated (all sessions ended) before deletion.
     */
    public function delete(User $user, Station $station): bool
    {
        // Must have manage-stations permission
        if (! $user->can('manage-stations')) {
            return false;
        }

        // Cannot delete stations with active operating sessions
        return ! $station->is_active;
    }

    /**
     * Determine whether the user can restore the station.
     *
     * Users can restore soft-deleted stations if they have the 'manage-stations'
     * permission. This allows recovery of accidentally deleted stations along
     * with their historical data and configurations.
     */
    public function restore(User $user, Station $station): bool
    {
        return $user->can('manage-stations');
    }

    /**
     * Determine whether the user can permanently delete the station.
     *
     * Users can permanently delete (force delete) stations if they have the
     * 'manage-stations' permission AND the station has no contacts logged.
     *
     * Stations with contact history should be preserved for record-keeping
     * and scoring verification. Only empty stations (no contacts) can be
     * permanently removed from the database.
     */
    public function forceDelete(User $user, Station $station): bool
    {
        // Must have manage-stations permission
        if (! $user->can('manage-stations')) {
            return false;
        }

        // Cannot force delete stations with contacts
        return $station->contacts()->count() === 0;
    }
}
