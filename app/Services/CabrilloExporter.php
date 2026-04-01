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
            'CONTEST: ARRL-FD',
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

        return "{$callsign}-{$year}-field-day.log";
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
        $freqKhz = $contact->band->frequency_mhz !== null
            ? (int) ($contact->band->frequency_mhz * 1000)
            : 0;
        $mode = $this->cabrilloMode($contact->mode->category);
        $date = $contact->qso_time->format('Y-m-d');
        $time = $contact->qso_time->format('Hi');
        $sentClass = $config->transmitter_count.$config->operatingClass->code;
        $sentSection = $config->section->code;

        // received_exchange stores "CALLSIGN CLASS SECTION" — skip the callsign
        $receivedTokens = preg_split('/\s+/', trim($contact->received_exchange ?? ''));
        $rcvdClass = $receivedTokens[1] ?? '';
        $rcvdSection = $receivedTokens[2] ?? '';

        return sprintf(
            'QSO: %5d %s %s %s %-13s %-4s %-5s %-13s %-4s %-5s',
            $freqKhz,
            $mode,
            $date,
            $time,
            $config->callsign,
            $sentClass,
            $sentSection,
            $contact->callsign,
            $rcvdClass,
            $rcvdSection
        );
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
