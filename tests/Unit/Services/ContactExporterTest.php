<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Services\ContactExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ContactExporterTest extends TestCase
{
    use RefreshDatabase;

    protected ContactExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new ContactExporter;
    }

    public function test_export_csv_returns_streamed_response(): void
    {
        $contacts = Collection::make([Contact::factory()->create()]);

        $response = $this->exporter->exportCsv($contacts);

        expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class)
            ->and($response->headers->get('content-type'))->toBe('text/csv');
    }

    public function test_csv_header_row_contains_all_expected_columns(): void
    {
        $contacts = Collection::make([Contact::factory()->create()]);

        $response = $this->exporter->exportCsv($contacts);

        // Capture the streamed content
        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $headers = str_getcsv($lines[0]);

        expect($headers)->toEqual([
            'QSO Time',
            'Callsign',
            'Band',
            'Mode',
            'Section',
            'Exchange',
            'Points',
            'Duplicate Status',
            'Logger',
            'Station',
        ]);
    }

    public function test_csv_date_format_is_iso8601(): void
    {
        $contact = Contact::factory()->create([
            'qso_time' => '2025-02-07 14:30:00',
        ]);

        $contacts = Collection::make([$contact]);
        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $dataRow = str_getcsv($lines[1]);

        expect($dataRow[0])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    }

    public function test_csv_duplicate_status_shows_yes_for_duplicates(): void
    {
        $contact = Contact::factory()->duplicate()->create();

        $contacts = Collection::make([$contact]);
        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $dataRow = str_getcsv($lines[1]);

        // Duplicate Status is the 8th column (index 7)
        expect($dataRow[7])->toBe('Yes');
    }

    public function test_csv_duplicate_status_shows_no_for_non_duplicates(): void
    {
        $contact = Contact::factory()->create(['is_duplicate' => false]);

        $contacts = Collection::make([$contact]);
        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $dataRow = str_getcsv($lines[1]);

        // Duplicate Status is the 8th column (index 7)
        expect($dataRow[7])->toBe('No');
    }

    public function test_csv_special_characters_are_properly_escaped(): void
    {
        $contact = Contact::factory()->create([
            'callsign' => 'W1AW/VE',
            'received_exchange' => 'ABC, "quote", comma',
            'notes' => 'Line 1'.PHP_EOL.'Line 2',
        ]);

        $contacts = Collection::make([$contact]);
        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $dataRow = str_getcsv($lines[1]);

        // Verify CSV parsing works correctly with special characters
        expect($dataRow[1])->toBe('W1AW/VE');
        expect($dataRow[5])->toContain('QUOTE');
    }

    public function test_empty_collection_returns_header_row_only(): void
    {
        $contacts = Collection::make([]);

        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = array_filter(explode("\n", trim($csvContent)));

        // Should have only the header row
        expect(count($lines))->toBe(1);
    }

    public function test_large_collection_completes_successfully(): void
    {
        $eventConfig = \App\Models\EventConfiguration::factory()->create();
        $session = \App\Models\OperatingSession::factory()->create();
        $contacts = Contact::factory()->count(150)->create([
            'event_configuration_id' => $eventConfig->id,
            'operating_session_id' => $session->id,
        ]);

        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = array_filter(explode("\n", trim($csvContent)));

        // Should have header + 150 data rows
        expect(count($lines))->toBe(151);
    }

    public function test_filename_format_is_correct(): void
    {
        $contacts = Collection::make([Contact::factory()->create()]);

        $response = $this->exporter->exportCsv($contacts);

        $contentDisposition = $response->headers->get('content-disposition');

        expect($contentDisposition)->toMatch('/^attachment; filename=field-day-logbook-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/');
    }

    public function test_csv_includes_related_model_data(): void
    {
        $contact = Contact::factory()->create();
        $contact->load(['band', 'mode', 'logger', 'operatingSession.station', 'section']);

        $contacts = Collection::make([$contact]);
        $response = $this->exporter->exportCsv($contacts);

        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        $lines = explode("\n", trim($csvContent));
        $dataRow = str_getcsv($lines[1]);

        // Band name should be in the output
        expect($dataRow[2])->not->toBeEmpty();
        // Mode name should be in the output
        expect($dataRow[3])->not->toBeEmpty();
    }
}
