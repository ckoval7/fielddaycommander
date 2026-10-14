<?php

use App\Models\Section;
use App\Support\CanadianSectionPicker;
use Illuminate\Support\Collection;

function canadianSections(): Collection
{
    return collect(['AB', 'BC', 'GH', 'MB', 'NB', 'NL', 'NS', 'ONE', 'ONN', 'ONS', 'PE', 'QC', 'SK', 'TER'])
        ->map(fn (string $code): Section => new Section(['code' => $code, 'country' => 'CA']));
}

test('isCanadianContact() keeps Canada a small minority of contacts', function () {
    $canadianCount = 0;
    foreach (range(1, 2000) as $i) {
        if (CanadianSectionPicker::isCanadianContact()) {
            $canadianCount++;
        }
    }

    expect($canadianCount / 2000)->toBeGreaterThan(0.03)->toBeLessThan(0.12);
});

test('pick() only returns sections from the given collection', function () {
    $sections = canadianSections();
    $codes = $sections->pluck('code')->all();

    foreach (range(1, 200) as $i) {
        expect($codes)->toContain(CanadianSectionPicker::pick($sections)->code);
    }
});

test('pick() makes TER much rarer than other Canadian sections', function () {
    $sections = canadianSections();
    $counts = [];
    foreach (range(1, 5000) as $i) {
        $code = CanadianSectionPicker::pick($sections)->code;
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }

    expect($counts['TER'] ?? 0)->toBeLessThan(60)
        ->and($counts['ONS'])->toBeGreaterThan(200);
});

test('pick() still returns TER when it is the only section available', function () {
    $sections = canadianSections()->filter(fn (Section $section): bool => $section->code === 'TER');

    expect(CanadianSectionPicker::pick($sections)->code)->toBe('TER');
});
