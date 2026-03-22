<?php

use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\User;

describe('Relationships', function () {
    test('belongs to checklist item', function () {
        $item = SafetyChecklistItem::factory()->create();
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        expect($entry->checklistItem)
            ->toBeInstanceOf(SafetyChecklistItem::class)
            ->id->toBe($item->id);
    });

    test('belongs to completed by user', function () {
        $user = User::factory()->create();
        $entry = SafetyChecklistEntry::factory()->create([
            'completed_by_user_id' => $user->id,
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        expect($entry->completedBy)
            ->toBeInstanceOf(User::class)
            ->id->toBe($user->id);
    });

    test('completed by is nullable', function () {
        $entry = SafetyChecklistEntry::factory()->create([
            'completed_by_user_id' => null,
        ]);

        expect($entry->completedBy)->toBeNull();
    });
});

describe('Completion', function () {
    test('markComplete sets completed fields', function () {
        $user = User::factory()->create();
        $entry = SafetyChecklistEntry::factory()->create();

        $entry->markComplete($user);

        expect($entry->fresh())
            ->is_completed->toBeTrue()
            ->completed_by_user_id->toBe($user->id)
            ->completed_at->not->toBeNull();
    });

    test('markIncomplete clears completed fields', function () {
        $user = User::factory()->create();
        $entry = SafetyChecklistEntry::factory()->create([
            'is_completed' => true,
            'completed_by_user_id' => $user->id,
            'completed_at' => now(),
        ]);

        $entry->markIncomplete();

        expect($entry->fresh())
            ->is_completed->toBeFalse()
            ->completed_by_user_id->toBeNull()
            ->completed_at->toBeNull();
    });
});
