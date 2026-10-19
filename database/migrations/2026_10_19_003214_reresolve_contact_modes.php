<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Services\ModeResolverService;
use App\Services\RescoreService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-resolve contacts the old mode list got wrong, then rescore their events.
 *
 * Mode resolution only accepted listed modes, so a contact whose sub-mode was
 * not on the list (fldigi's PSK125, MFSK16, OLIVIA 8/250 ...) was stored with
 * no mode: 0 points, no dupe check, no Cabrillo line. Digital voice (FreeDV,
 * D-STAR, DMR, C4FM, M17) was filed as Digital instead of Phone.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const DIGITAL_VOICE_SUBMODES = ['DIGITALVOICE', 'FREEDV', 'DSTAR', 'DMR', 'C4FM', 'M17'];

    public function up(): void
    {
        $resolver = app(ModeResolverService::class);
        $configIds = collect();

        $unresolved = DB::table('contacts')
            ->whereNull('mode_id')
            ->whereNotNull('submode')
            ->where('submode', '!=', '');

        foreach ((clone $unresolved)->distinct()->pluck('submode') as $submode) {
            $modeId = $resolver->resolve($submode);

            if ($modeId === null) {
                continue;
            }

            $matching = (clone $unresolved)->where('submode', $submode);
            $configIds = $configIds->merge($matching->distinct()->pluck('event_configuration_id'));
            $matching->update(['mode_id' => $modeId]);
        }

        $digitalId = DB::table('modes')->where('name', 'Digital')->value('id');
        $phoneId = DB::table('modes')->where('name', 'Phone')->value('id');

        if ($digitalId !== null && $phoneId !== null) {
            $digitalVoice = DB::table('contacts')
                ->where('mode_id', $digitalId)
                ->whereIn(DB::raw('UPPER(submode)'), self::DIGITAL_VOICE_SUBMODES);

            $configIds = $configIds->merge((clone $digitalVoice)->distinct()->pluck('event_configuration_id'));
            $digitalVoice->update(['mode_id' => $phoneId]);
        }

        $this->rescore($configIds->filter()->unique());
    }

    public function down(): void
    {
        // The re-resolved modes can't be told apart from ones logged correctly.
    }

    /**
     * Re-settle dupes and points for each affected event, as a rescore does.
     *
     * @param  Collection<int, int>  $configIds
     */
    private function rescore(Collection $configIds): void
    {
        if ($configIds->isEmpty()) {
            return;
        }

        $rescorer = app(RescoreService::class);

        Event::query()
            ->whereHas('eventConfiguration', fn ($query) => $query->whereIn('id', $configIds))
            ->get()
            ->each(function (Event $event) use ($rescorer) {
                try {
                    $rescorer->rescoreEvent($event);
                } catch (UnknownRuleSet) {
                    // An event on a rules version no longer shipped keeps its stored points.
                }
            });
    }
};
