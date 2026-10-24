<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Services\EventContextService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * InfoCard Widget
 *
 * Displays event information in a row-based key/value layout. Three variants:
 *
 * - event_details: name, location (section), operating class, callsign
 * - location: grid square, lat/lon, city/state, talk-in frequency
 * - operating_class: class, transmitter count, GOTA, max power, power sources
 *
 * Both size variants (normal, tv) iterate the same row payload.
 */
class InfoCard extends Component
{
    use IsWidget;

    public function getData(): array
    {
        if ($this->shouldCache()) {
            return Cache::remember(
                $this->cacheKey(),
                now()->addSeconds(60),
                fn () => $this->gatherEventInfo()
            );
        }

        return $this->gatherEventInfo();
    }

    public function getWidgetListeners(): array
    {
        return [];
    }

    /**
     * Build the payload for the configured info_type variant.
     *
     * @return array{title: string, rows: array<int, array{label: string, value: string, highlight?: bool}>, last_updated_at: CarbonInterface}
     */
    protected function gatherEventInfo(): array
    {
        $infoType = $this->config['info_type'] ?? 'event_details';
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        return match ($infoType) {
            'location' => $this->locationInfo($event),
            'operating_class' => $this->operatingClassInfo($event),
            default => $this->eventDetailsInfo($event),
        };
    }

    /**
     * Event Details: name, location (section), operating class, callsign.
     *
     * @return array{title: string, rows: array<int, array{label: string, value: string, highlight?: bool}>, last_updated_at: CarbonInterface}
     */
    protected function eventDetailsInfo(?Event $event): array
    {
        $config = $event?->eventConfiguration;

        // Unscored events have no ARRL section or operating class, so show
        // where the event is instead.
        $isScored = $event?->isScored() ?? true;
        $rows = $isScored
            ? [
                ['label' => 'Event', 'value' => $event?->name ?? 'N/A'],
                ['label' => 'Section', 'value' => $config?->section?->name ?? 'N/A'],
                ['label' => 'Class', 'value' => $this->entryClass($config) ?? 'N/A'],
            ]
            : [
                ['label' => 'Event', 'value' => $event->name],
                ['label' => 'Location', 'value' => $this->cityState($config)],
            ];

        return [
            'title' => 'Event Info',
            'rows' => [
                ...$rows,
                ['label' => 'Call Sign', 'value' => $config?->callsign ?? 'N/A', 'highlight' => true],
            ],
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Location: city/state, grid square, lat/lon, talk-in frequency.
     *
     * @return array{title: string, rows: array<int, array{label: string, value: string, highlight?: bool}>, last_updated_at: CarbonInterface}
     */
    protected function locationInfo(?Event $event): array
    {
        $config = $event?->eventConfiguration;

        $coordinates = ($config?->latitude !== null && $config?->longitude !== null)
            ? sprintf('%.4f, %.4f', $config->latitude, $config->longitude)
            : 'N/A';

        $talkIn = $config?->talk_in_frequency
            ? $config->talk_in_frequency.' MHz'
            : 'N/A';

        return [
            'title' => 'Location',
            'rows' => [
                ['label' => 'City / State', 'value' => $this->cityState($config)],
                ['label' => 'Grid Square', 'value' => $config?->grid_square ?: 'N/A', 'highlight' => true],
                ['label' => 'Coordinates', 'value' => $coordinates],
                ['label' => 'Talk-in', 'value' => $talkIn],
            ],
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Operating Class: class, transmitter count, GOTA, max power, power sources.
     *
     * @return array{title: string, rows: array<int, array{label: string, value: string, highlight?: bool}>, last_updated_at: CarbonInterface}
     */
    protected function operatingClassInfo(?Event $event): array
    {
        $config = $event?->eventConfiguration;

        $entryClass = $this->entryClass($config);
        $className = $config?->operatingClass?->name;
        $classSuffix = $className ? ": {$className}" : '';
        $classDisplay = $entryClass ? "{$entryClass}{$classSuffix}" : 'N/A';

        $transmitters = $config?->transmitter_count !== null
            ? (string) $config->transmitter_count
            : 'N/A';

        $gotaLabel = $config?->gota_callsign ? "Yes ({$config->gota_callsign})" : 'Yes';
        $gota = $config?->has_gota_station ? $gotaLabel : 'No';

        $maxWatts = $config?->max_power_watts !== null
            ? number_format((int) $config->max_power_watts).' W'
            : 'N/A';

        $powerSources = $this->describePowerSources($config);

        return [
            'title' => 'Operating Class',
            'rows' => [
                ['label' => 'Class', 'value' => $classDisplay, 'highlight' => true],
                ['label' => 'Transmitters', 'value' => $transmitters],
                ['label' => 'GOTA Station', 'value' => $gota],
                ['label' => 'Max Power', 'value' => $maxWatts],
                ['label' => 'Power Sources', 'value' => $powerSources],
            ],
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Format the configured power sources as a comma-separated list.
     */
    /**
     * Field Day entry class as reported, e.g. "3A": transmitter count plus
     * the operating class letter. Null when no class is configured.
     */
    protected function entryClass(mixed $config): ?string
    {
        $classCode = $config?->operatingClass?->code;

        if (! $classCode) {
            return null;
        }

        // Some data stores the full entry class ("3A") as the code already.
        if (ctype_digit($classCode[0])) {
            return $classCode;
        }

        $transmitters = $config->transmitter_count;

        return $transmitters ? "{$transmitters}{$classCode}" : $classCode;
    }

    protected function describePowerSources(mixed $config): string
    {
        if (! $config) {
            return 'N/A';
        }

        $labels = [
            'uses_commercial_power' => 'Mains',
            'uses_generator' => 'Generator',
            'uses_battery' => 'Battery',
            'uses_solar' => 'Solar',
            'uses_wind' => 'Wind',
            'uses_water' => 'Water',
            'uses_methane' => 'Methane',
            'uses_other_power' => 'Other',
        ];

        $active = [];

        foreach ($labels as $field => $label) {
            if ($config->{$field}) {
                $active[] = $label;
            }
        }

        return $active === [] ? 'None' : implode(', ', $active);
    }

    public function render()
    {
        return view('livewire.dashboard.widgets.info-card', [
            'data' => $this->getData(),
        ]);
    }

    protected function cityState(?EventConfiguration $config): string
    {
        return collect([$config?->city, $config?->state])->filter()->implode(', ') ?: 'N/A';
    }
}
