<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use App\Models\Checklist;
use App\Models\ChecklistRun;
use App\Models\EventConfiguration;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_24_000001_move_safety_checklists_to_checklist_records.php');
    $this->migration->down();
});

function legacyItem(EventConfiguration $config, string $type, int $sort, bool $completed = false, bool $required = true, array $entry = [], bool $deleted = false): int
{
    $itemId = DB::table('safety_checklist_items')->insertGetId([
        'event_configuration_id' => $config->id,
        'checklist_type' => $type,
        'label' => "{$type} item {$sort}",
        'help_text' => "Help for {$sort}",
        'is_required' => $required,
        'is_default' => $type !== 'briefing',
        'sort_order' => $sort,
        'deleted_at' => $deleted ? now() : null,
    ]);

    DB::table('safety_checklist_entries')->insert([
        'safety_checklist_item_id' => $itemId,
        'is_completed' => $completed,
        ...$entry,
    ]);

    return $itemId;
}

test('a completed safety officer checklist becomes a complete event run that keeps its bonus gate', function () {
    $config = EventConfiguration::factory()->create();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Safety Officer']);
    $officer = User::factory()->create();

    legacyItem($config, 'safety_officer', 1, completed: true, entry: ['completed_by_user_id' => $officer->id, 'completed_at' => '2026-06-27 18:00:00', 'notes' => 'Two extinguishers']);
    legacyItem($config, 'safety_officer', 0, completed: true, entry: ['completed_by_user_id' => $officer->id, 'completed_at' => '2026-06-27 19:30:00']);

    $this->migration->up();

    $checklist = Checklist::sole();
    $run = ChecklistRun::sole();

    expect($checklist->name)->toBe('Safety Officer')
        ->and($checklist->scope)->toBe(ChecklistScope::Event)
        ->and($checklist->bonus_code)->toBe('safety_officer')
        ->and($checklist->completion_rule)->toBe(ChecklistCompletionRule::AllItems)
        ->and($checklist->shiftRoles->pluck('id')->all())->toBe([$role->id])
        ->and($checklist->items->pluck('label')->all())->toBe(['safety_officer item 0', 'safety_officer item 1'])
        ->and($checklist->items->every->is_default)->toBeTrue()
        ->and($run->subject_key)->toBe('event')
        ->and($run->completed_at->toDateTimeString())->toBe('2026-06-27 19:30:00');

    $answer = $run->answers()->whereHas('item', fn ($q) => $q->where('label', 'safety_officer item 1'))->sole();

    expect($answer->value)->toBe('1')
        ->and($answer->notes)->toBe('Two extinguishers')
        ->and($answer->answered_by_user_id)->toBe($officer->id)
        ->and($answer->answered_at->toDateTimeString())->toBe('2026-06-27 18:00:00');
});

test('an unfinished checklist moves without completing its run, keeping notes on unchecked items', function () {
    $config = EventConfiguration::factory()->create();

    legacyItem($config, 'site_responsibilities', 0, completed: true);
    legacyItem($config, 'site_responsibilities', 1, completed: false, entry: ['notes' => 'Waiting on cones']);
    legacyItem($config, 'site_responsibilities', 2, completed: false);

    $this->migration->up();

    $run = ChecklistRun::sole();

    expect(Checklist::sole()->completion_rule)->toBe(ChecklistCompletionRule::RequiredAndMajority)
        ->and($run->completed_at)->toBeNull()
        ->and($run->answers()->count())->toBe(2)
        ->and($run->answers()->whereNull('value')->sole()->notes)->toBe('Waiting on cones');
});

test('each event and checklist type becomes its own checklist, skipping deleted items', function () {
    $fieldDay = EventConfiguration::factory()->create();
    $parade = EventConfiguration::factory()->publicService()->create();

    legacyItem($fieldDay, 'site_responsibilities', 0);
    legacyItem($fieldDay, 'site_responsibilities', 1, deleted: true);
    legacyItem($parade, 'briefing', 0);
    legacyItem($parade, 'briefing', 1, required: false);

    $this->migration->up();

    $briefing = Checklist::where('event_configuration_id', $parade->id)->sole();

    expect(Checklist::where('event_configuration_id', $fieldDay->id)->sole()->items()->count())->toBe(1)
        ->and($briefing->name)->toBe('Volunteer Briefing')
        ->and($briefing->bonus_code)->toBeNull()
        ->and($briefing->items()->count())->toBe(2)
        ->and($briefing->items->contains->is_default)->toBeFalse()
        ->and(Schema::hasTable('safety_checklist_items'))->toBeFalse()
        ->and(Schema::hasTable('safety_checklist_entries'))->toBeFalse();
});
