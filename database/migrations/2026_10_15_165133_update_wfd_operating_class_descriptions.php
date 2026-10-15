<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the WFD operating class descriptions in line with the 2027 rules
 * (Class and Category definitions). Production updates do not run seeders.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{old: string, new: string}>
     */
    private function descriptions(): array
    {
        return [
            'H' => ['old' => 'Operation from home QTH', 'new' => 'Home station: operating inside a permanent livable residence'],
            'I' => ['old' => 'Operation from indoor location (not home)', 'new' => 'Indoor station: away from home, inside an insulated, weather-protected building on a permanent foundation'],
            'O' => ['old' => 'Operation from outdoor location', 'new' => 'Outdoor station: partly or fully exposed shelter without plumbing, HVAC or running water (tents, pavilions, canopies)'],
            'M' => ['old' => 'Mobile operation', 'new' => 'Mobile station: operating from a mobile or potentially mobile structure such as an RV, car, van or trailer'],
        ];
    }

    public function up(): void
    {
        $this->apply('new');
    }

    public function down(): void
    {
        $this->apply('old');
    }

    private function apply(string $key): void
    {
        $eventTypeId = DB::table('event_types')->where('code', 'WFD')->value('id');

        if ($eventTypeId === null) {
            return;
        }

        foreach ($this->descriptions() as $code => $text) {
            DB::table('operating_classes')
                ->where('event_type_id', $eventTypeId)
                ->where('code', $code)
                ->update(['description' => $text[$key]]);
        }
    }
};
