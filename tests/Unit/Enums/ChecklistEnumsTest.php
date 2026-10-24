<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistAnswerType;
use App\Enums\ChecklistCompletionRule;

test('completion rules', function (ChecklistCompletionRule $rule, array $counts, bool $met) {
    expect($rule->isMet(...$counts))->toBe($met);
})->with([
    'all items: all done' => [ChecklistCompletionRule::AllItems, [3, 3, 1, 1], true],
    'all items: one open' => [ChecklistCompletionRule::AllItems, [3, 2, 1, 1], false],
    'required: required done' => [ChecklistCompletionRule::RequiredItems, [3, 1, 1, 1], true],
    'required: required open' => [ChecklistCompletionRule::RequiredItems, [3, 2, 1, 0], false],
    'required: none required needs everything' => [ChecklistCompletionRule::RequiredItems, [2, 1, 0, 0], false],
    'required: none required, all done' => [ChecklistCompletionRule::RequiredItems, [2, 2, 0, 0], true],
    'majority: required and over half' => [ChecklistCompletionRule::RequiredAndMajority, [4, 3, 2, 2], true],
    'majority: exactly half' => [ChecklistCompletionRule::RequiredAndMajority, [4, 2, 2, 2], false],
    'majority: required open' => [ChecklistCompletionRule::RequiredAndMajority, [4, 3, 2, 1], false],
    'empty checklist' => [ChecklistCompletionRule::RequiredItems, [0, 0, 0, 0], false],
]);

test('answers are normalized by type', function (ChecklistAnswerType $type, mixed $input, ?string $stored, ?string $display) {
    expect($type->normalize($input))->toBe($stored)
        ->and($type->display($stored))->toBe($display);
})->with([
    'ticked' => [ChecklistAnswerType::Checkbox, true, '1', 'Done'],
    'unticked' => [ChecklistAnswerType::Checkbox, false, null, null],
    'yes' => [ChecklistAnswerType::YesNoNa, 'yes', 'yes', 'Yes'],
    'n/a' => [ChecklistAnswerType::YesNoNa, 'na', 'na', 'N/A'],
    'unknown choice' => [ChecklistAnswerType::YesNoNa, 'perhaps', null, null],
    'text trimmed' => [ChecklistAnswerType::Text, '  hello ', 'hello', 'hello'],
    'blank text' => [ChecklistAnswerType::Text, '   ', null, null],
    'number' => [ChecklistAnswerType::Number, '13.80', '13.8', '13.8'],
    'not a number' => [ChecklistAnswerType::Number, '13.8V', null, null],
]);
