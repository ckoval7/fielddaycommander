<?php

namespace App\Services;

use App\Exceptions\CabrilloExportException;
use App\Models\Contact;
use App\Models\EventConfiguration;

class CabrilloExporter
{
    /**
     * Build a Cabrillo 3.0 log file for the given event configuration.
     */
    public function export(EventConfiguration $config): string
    {
        $config->loadMissing(['section', 'operatingClass', 'event']);

        if (! $config->section || ! $config->operatingClass) {
            throw new CabrilloExportException('EventConfiguration is missing section or operating class.');
        }

        $lines = [
            'START-OF-LOG: 3.0',
            'CREATED-BY: FD Commander',
            'CONTEST: '.$config->cabrilloContestName(),
            'CALLSIGN: '.$config->callsign,
            'LOCATION: '.$config->section->code,
            'CATEGORY-OPERATOR: MULTI-OP',
            'CATEGORY-BAND: ALL',
            'CATEGORY-MODE: MIXED',
            'CATEGORY-POWER: '.$this->powerCategory($config->max_power_watts),
            'CATEGORY-STATION: '.$this->stationCategory($config->operatingClass->code),
            'CLAIMED-SCORE: '.$config->calculateFinalScore(),
        ];

        if ($config->club_name) {
            $lines[] = 'CLUB: '.$config->club_name;
        }

        // GOTA SOAPBOX lines
        if ($config->has_gota_station && $config->gota_callsign) {
            $gotaCount = $config->contacts()
                ->where('is_gota_contact', true)
                ->where('is_duplicate', false)
                ->count();

            $supervisedCount = $config->contacts()
                ->where('is_gota_contact', true)
                ->where('is_duplicate', false)
                ->whereHas('operatingSession', fn ($q) => $q->where('is_supervised', true))
                ->count();

            $lines[] = 'SOAPBOX: GOTA Station Callsign: '.$config->gota_callsign;
            $lines[] = 'SOAPBOX: GOTA Contacts: '.$gotaCount.' (Supervised: '.$supervisedCount.')';

            // List unique GOTA operators
            $gotaOperators = $config->contacts()
                ->where('is_gota_contact', true)
                ->where('is_duplicate', false)
                ->whereNotNull('gota_operator_first_name')
                ->select('gota_operator_first_name', 'gota_operator_last_name', 'gota_operator_callsign')
                ->distinct()
                ->get();

            if ($gotaOperators->isNotEmpty()) {
                $opList = $gotaOperators->map(function ($op) {
                    $name = trim($op->gota_operator_first_name.' '.$op->gota_operator_last_name);

                    return $op->gota_operator_callsign ? "{$name} ({$op->gota_operator_callsign})" : $name;
                })->implode(', ');
                $lines[] = 'SOAPBOX: GOTA Operators: '.$opList;
            }
        }

        $contacts = $config->contacts()
            ->notDuplicate()
            ->with(['band', 'mode'])
            ->orderBy('qso_time')
            ->get();

        foreach ($contacts as $contact) {
            $lines[] = $this->formatQso($config, $contact);
        }

        $lines[] = 'END-OF-LOG:';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Generate the download filename for the log.
     */
    public function filename(EventConfiguration $config): string
    {
        $config->loadMissing('event');
        $year = $config->event->start_time->year;
        $callsign = strtolower($config->callsign);

        return "{$callsign}-{$year}-{$config->logFilenameSlug()}.log";
    }

    private function powerCategory(int $watts): string
    {
        if ($watts <= 5) {
            return 'QRP';
        }

        if ($watts <= 100) {
            return 'LOW';
        }

        return 'HIGH';
    }

    private function formatQso(EventConfiguration $config, Contact $contact): string
    {
        $freqKhz = $this->cabrilloFrequency($contact->band->frequency_mhz);
        $mode = $this->cabrilloMode($contact->mode->category);
        $date = $contact->qso_time->format('Y-m-d');
        $time = $contact->qso_time->format('Hi');
        $sentClass = $config->transmitter_count.$config->operatingClass->code;
        $sentSection = $config->section->code;

        // Use GOTA callsign for GOTA contacts
        $sentCallsign = ($contact->is_gota_contact && $config->gota_callsign)
            ? $config->gota_callsign
            : $config->callsign;

        $rcvdClass = $contact->exchange_class ?? '';
        $rcvdSection = $contact->section?->code ?? '';

        return sprintf(
            'QSO: %5s %s %s %s %-13s %-4s %-5s %-13s %-4s %-5s',
            $freqKhz,
            $mode,
            $date,
            $time,
            $sentCallsign,
            $sentClass,
            $sentSection,
            $contact->callsign,
            $rcvdClass,
            $rcvdSection
        );
    }

    /**
     * Cabrillo frequency field: kHz below 1.2 GHz, band designators (1.2G, 10G, ...) above it.
     */
    private function cabrilloFrequency(float|string|null $frequencyMhz): int|string
    {
        if ($frequencyMhz === null) {
            return 0;
        }

        $frequencyMhz = (float) $frequencyMhz;

        if ($frequencyMhz < 1000) {
            return (int) round($frequencyMhz * 1000);
        }

        $designators = [
            1240 => '1.2G', 2300 => '2.3G', 3300 => '3.4G', 5650 => '5.7G', 10000 => '10G',
            24000 => '24G', 47000 => '47G', 76000 => '75G', 122250 => '122G', 134000 => '134G', 241000 => '241G',
        ];

        return $designators[(int) $frequencyMhz] ?? ((int) round($frequencyMhz / 1000)).'G';
    }

    private function cabrilloMode(string $category): string
    {
        return match ($category) {
            'CW' => 'CW',
            'Phone' => 'PH',
            default => 'DG',
        };
    }

    private function stationCategory(string $classCode): string
    {
        return match ($classCode) {
            'C', 'M' => 'MOBILE',
            'B', 'D', 'E', 'F', 'H', 'I' => 'FIXED',
            default => 'PORTABLE',
        };
    }
}
