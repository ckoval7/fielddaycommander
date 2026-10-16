<?php

use App\View\Components\ChoicesOffline;
use Illuminate\Support\Facades\Blade;
use Mary\View\Components\ChoicesOffline as MaryChoicesOffline;

it('still finds every getter it patches in Mary\'s template', function () {
    $template = (new MaryChoicesOffline(options: collect()))->render();

    foreach (array_keys(ChoicesOffline::NULL_SAFE_GETTERS) as $original) {
        expect($template)->toContain($original);
    }
});

it('makes the selection getters null-safe', function () {
    $template = (new ChoicesOffline(options: collect()))->render();

    expect($template)
        ->toContain('return this.options.length == this.selection?.length')
        ->toContain(': this.selection?.length == 0')
        ->toContain(': this.selection?.map(i =>')
        ->not->toContain('this.selection.length')
        ->not->toContain('this.selection.map(');
});

it('replaces Mary\'s <x-choices-offline>', function () {
    expect(Blade::getClassComponentAliases()[config('mary.prefix').'choices-offline'])->toBe(ChoicesOffline::class);
});
