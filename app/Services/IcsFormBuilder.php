<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\EventConfiguration;
use App\Models\Message;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the field values for FEMA's fillable ICS PDFs.
 *
 * Each form is returned as a list of pages, and each page as a map of the
 * FEMA PDF's own field names to values. The browser fills one blank copy of
 * the form per page and merges them, so the field mapping lives here where it
 * can be tested.
 *
 * @phpstan-type IcsForm array{template: string, filename: string, pages: list<array<string, string>>}
 */
class IcsFormBuilder
{
    /**
     * Table rows on one page of the ICS-205 and ICS-211.
     */
    public const ROWS_PER_PAGE = 8;

    private const DATE_FORMAT = 'Y-m-d';

    private const TIME_FORMAT = 'H:i';

    private const DATETIME_FORMAT = 'Y-m-d H:i';

    /**
     * Digital voice and data modes, reported as "D" in the ICS-205 mode column.
     */
    private const DIGITAL_MODES = ['DMR', 'D-STAR', 'DSTAR', 'P25', 'NXDN', 'C4FM', 'FUSION', 'YSF', 'M17', 'PACKET', 'APRS', 'WINLINK', 'VARA', 'DIGITAL'];

    /**
     * ICS-205 Incident Radio Communications Plan: one row per channel.
     *
     * @return IcsForm
     */
    public function ics205(EventConfiguration $config, ?User $preparedBy): array
    {
        $event = $config->event;

        $header = [
            '1 Incident Name_8' => (string) $event->name,
            '2 Date/Time Prepared' => $this->now()->format(self::DATETIME_FORMAT),
            'Date From' => $this->formatLocal($event->start_time, self::DATE_FORMAT),
            'Date To' => $this->formatLocal($event->end_time, self::DATE_FORMAT),
            'Time From' => $this->formatLocal($event->start_time, self::TIME_FORMAT),
            'Time To' => $this->formatLocal($event->end_time, self::TIME_FORMAT),
            '5 Special Instructions' => "Club callsign: {$config->callsign}",
            '6 Prepared by Communications Unit Leader Name' => $this->personName($preparedBy),
            'DateTime_8' => $this->now()->format(self::DATETIME_FORMAT),
        ];

        $channels = $this->channels($config);

        return [
            'template' => asset('forms/ics/ics-205.pdf'),
            'filename' => $this->filename('ics-205', $event->name),
            'pages' => $this->paginate($header, $channels, function (array $channel, int $row, int $number): array {
                return [
                    "Ch Row{$row}" => (string) $number,
                    "FunctionRow{$row}" => $channel['function'],
                    "Channel NameTrunked Radio System TalkgroupRow{$row}" => $channel['name'],
                    "AssignmentRow{$row}" => $channel['assignment'],
                    "RX Freq N or WRow{$row}" => $channel['rx'],
                    "RX ToneNACRow{$row}" => $channel['tone'],
                    "TX Freq N or WRow{$row}" => $channel['tx'],
                    "TX ToneNACRow{$row}" => $channel['tone'],
                    "Mode A D or MRow{$row}" => $channel['mode_letter'],
                    "RemarksRow{$row}" => $channel['remarks'],
                ];
            }),
        ];
    }

    /**
     * ICS-211 Incident Check-In List: one row per shift check-in.
     *
     * @return IcsForm
     */
    public function ics211(EventConfiguration $config, ?User $preparedBy): array
    {
        $event = $config->event;

        $header = [
            '1 Incident Name' => (string) $event->name,
            'Start Date' => $this->formatLocal($event->start_time, self::DATE_FORMAT),
            'Start Time' => $this->formatLocal($event->start_time, self::TIME_FORMAT),
            'Name' => $this->personName($preparedBy),
            'PositionTitle_12' => 'Communications',
            'Date/Time' => $this->now()->format(self::DATETIME_FORMAT),
        ];

        $checkIns = ShiftAssignment::query()
            ->whereNotNull('checked_in_at')
            ->whereHas('shift', fn ($query) => $query->forEvent($config->id))
            ->with(['user', 'shift.shiftRole'])
            ->orderBy('checked_in_at')
            ->get();

        return [
            'template' => asset('forms/ics/ics-211.pdf'),
            'filename' => $this->filename('ics-211', $event->name),
            'pages' => $this->paginate($header, $checkIns, function (ShiftAssignment $assignment, int $row) use ($config): array {
                $role = $assignment->shift?->shiftRole;

                return [
                    "R{$row} Resource Name or ID {$row}" => (string) $assignment->user?->call_sign,
                    "R{$row} Leader's Name {$row}" => $this->personName($assignment->user, withCallsign: false),
                    "R{$row} Total Personnel {$row}" => '1',
                    "R{$row} Agency {$row}" => (string) $config->club_name,
                    "R{$row} Incident Assignment {$row}" => (string) ($role?->tactical_callsign ?: $role?->name),
                    "R{$row} Date/Time Check In {$row}" => $this->formatLocal($assignment->checked_in_at, self::DATETIME_FORMAT),
                ];
            }),
        ];
    }

    /**
     * ICS-213 General Message for one ICS-213 message.
     *
     * @return IcsForm
     */
    public function ics213(Message $message): array
    {
        $event = $message->eventConfiguration?->event;

        return [
            'template' => asset('forms/ics/ics-213.pdf'),
            'filename' => $this->filename('ics-213', ($event?->name ?? 'message').' '.$message->message_number),
            'pages' => [[
                '1 Incident Name Optional' => (string) $event?->name,
                '2 To Name and Position' => $this->joinNameAndPosition($message->addressee_name, $message->ics_to_position),
                '3 From Name and Position' => $this->joinNameAndPosition($message->signature, $message->ics_from_position),
                '4 Subject' => (string) $message->ics_subject,
                '5 Date' => $this->formatLocal($message->filed_at, self::DATE_FORMAT),
                '6 Time' => $this->formatLocal($message->filed_at, self::TIME_FORMAT),
                '7 Message' => (string) $message->message_text,
                '9 Reply' => (string) $message->ics_reply_text,
                '10 Replied by Name' => (string) $message->ics_reply_name,
                'PositionTitle_14' => (string) $message->ics_reply_position,
                'DateTime_14' => $this->formatLocal($message->ics_reply_date, self::DATETIME_FORMAT),
            ]],
        ];
    }

    /**
     * Radio channels for the ICS-205: the talk-in frequency, then every
     * station with a frequency or tactical callsign. A station's remarks
     * carry its mode and where it is.
     *
     * @return Collection<int, array{function: string, name: string, assignment: string, rx: string, tx: string, tone: string, mode_letter: string, remarks: string}>
     */
    protected function channels(EventConfiguration $config): Collection
    {
        $channels = collect();

        if ($config->talk_in_frequency) {
            $frequency = $this->firstFrequency($config->talk_in_frequency);

            $channels->push([
                'function' => 'Talk-in',
                'name' => 'Talk-in',
                'assignment' => (string) $config->callsign,
                'rx' => $frequency,
                'tx' => $frequency,
                'tone' => '',
                'mode_letter' => '',
                'remarks' => $config->talk_in_frequency,
            ]);
        }

        $stations = $config->stations()
            ->where(fn ($query) => $query
                ->whereNotNull('rx_frequency')
                ->orWhereNotNull('tx_frequency')
                ->orWhereNotNull('tactical_callsign'))
            ->orderBy('name')
            ->get();

        return $channels->concat($stations->map(fn (Station $station): array => [
            'function' => 'Tactical',
            'name' => $station->name,
            'assignment' => (string) $station->tactical_callsign,
            'rx' => (string) $station->rx_frequency,
            'tx' => (string) ($station->tx_frequency ?: $station->rx_frequency),
            'tone' => (string) $station->tone,
            'mode_letter' => $this->modeLetter($station->comms_mode),
            'remarks' => collect([$station->comms_mode, $station->location])->filter()->implode(', '),
        ]))->values();
    }

    /**
     * Split rows into form pages of ROWS_PER_PAGE, repeating the header on
     * each page. A form with no rows still gets one page.
     *
     * @param  array<string, string>  $header
     * @param  Collection<int, mixed>  $rows
     * @param  callable(mixed, int, int): array<string, string>  $rowFields  row item, row on the page (1-based), number across the form (1-based)
     * @return list<array<string, string>>
     */
    protected function paginate(array $header, Collection $rows, callable $rowFields): array
    {
        if ($rows->isEmpty()) {
            return [$header];
        }

        return $rows->values()
            ->chunk(self::ROWS_PER_PAGE)
            ->map(fn (Collection $chunk): array => array_merge($header, ...$chunk
                ->map(fn (mixed $item, int $index): array => $rowFields($item, $index % self::ROWS_PER_PAGE + 1, $index + 1))
                ->all()))
            ->values()
            ->all();
    }

    /**
     * ICS-205 mode column: A (analog), D (digital) or M (mixed).
     */
    protected function modeLetter(?string $mode): string
    {
        $mode = strtoupper(trim((string) $mode));

        if ($mode === '') {
            return '';
        }

        return in_array($mode, self::DIGITAL_MODES, true) ? 'D' : 'A';
    }

    /**
     * The first frequency in a free-text talk-in entry such as
     * "146.520 MHz simplex", or the whole text when it has none.
     */
    protected function firstFrequency(string $text): string
    {
        return preg_match('/\d+\.\d+/', $text, $matches) ? $matches[0] : $text;
    }

    protected function joinNameAndPosition(?string $name, ?string $position): string
    {
        return collect([$name, $position])->map(fn ($part) => trim((string) $part))->filter()->implode(', ');
    }

    /**
     * "First Last, CALLSIGN", or just the name without the callsign.
     */
    public function personName(?User $user, bool $withCallsign = true): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        if (! $withCallsign) {
            return $name;
        }

        return collect([$name, $user->call_sign])->filter()->implode(', ');
    }

    /**
     * A time in the event's local zone, or '' when there is none.
     */
    protected function formatLocal(mixed $time, string $format): string
    {
        return $time ? toLocalTime($time)->format($format) : '';
    }

    protected function now(): Carbon
    {
        return toLocalTime(appNow());
    }

    protected function filename(string $form, string $name): string
    {
        return $form.'-'.Str::slug($name).'.pdf';
    }
}
