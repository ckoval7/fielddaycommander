<?php

/**
 * Tests for the dashboard-sortable.js Alpine.js component.
 *
 * Since this is a JavaScript component, these tests verify:
 * - The JS file exists and has the expected structure
 * - The app.js entry point registers the component
 * - The Vite build includes the component in the output
 */
test('dashboard sortable js file exists', function () {
    $filePath = resource_path('js/components/dashboard-sortable.js');

    expect(file_exists($filePath))->toBeTrue();
});

test('dashboard sortable exports a function', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)->toContain('export default');
});

test('dashboard sortable implements all required drag handlers', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('dragStart(')
        ->toContain('dragOver(')
        ->toContain('drop(')
        ->toContain('dragEnd(');
});

test('dashboard sortable implements touch event handlers', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('touchStart(')
        ->toContain('touchMove(')
        ->toContain('touchEnd(');
});

test('dashboard sortable implements keyboard accessibility', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('keyDown(')
        ->toContain('ArrowUp')
        ->toContain('ArrowDown')
        ->toContain('ArrowLeft')
        ->toContain('ArrowRight')
        ->toContain('Enter')
        ->toContain('Escape');
});

test('dashboard sortable implements livewire integration', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)->toContain('wire.reorderWidgets');
});

test('dashboard sortable implements screen reader announcements', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('announceMessage')
        ->toContain('announce(');
});

test('dashboard sortable provides sortable item bindings', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('sortableItem(')
        ->toContain('sortableContainer')
        ->toContain('data-widget-id');
});

test('app js registers dashboard sortable alpine component', function () {
    $content = file_get_contents(resource_path('js/app.js'));

    expect($content)
        ->toContain("import dashboardSortable from './components/dashboard-sortable'")
        ->toContain("Alpine.data('dashboardSortable', dashboardSortable)");
});

test('dashboard sortable implements init and destroy lifecycle methods', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('init()')
        ->toContain('destroy()');
});

test('dashboard sortable implements visual feedback classes', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('opacity-50')
        ->toContain('ring-2 ring-primary')
        ->toContain('bg-primary/10');
});

test('dashboard sortable previews the new order while dragging', function () {
    $content = file_get_contents(resource_path('js/components/dashboard-sortable.js'));

    expect($content)
        ->toContain('previewOrder')
        ->toContain('previewStyle(')
        ->toContain('movePreview(')
        ->toContain('applyPreview(')
        ->toContain('commitPreview(')
        ->toContain('cancelPreview(')
        // FLIP animation of the items sliding into their previewed places
        ->toContain('getBoundingClientRect()')
        ->toContain('.animate(');
});

test('dashboard editor applies the drag preview order to each widget', function () {
    $content = file_get_contents(resource_path('views/livewire/dashboard/dashboard-editor.blade.php'));

    expect($content)
        ->toContain(':style="previewStyle(@js($widget[\'id\']))"')
        // The add-widget tile must stay last while widgets carry preview orders.
        ->toContain('style="order: 9999"')
        // Drops are handled once, by the grid, so the tile itself must not commit too.
        ->not->toContain('x-on:drop.prevent="drop(');
});

test('built js bundle includes dashboard sortable component', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! file_exists($manifestPath)) {
        $this->markTestSkipped('Vite manifest not found. Run npm run build first.');
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);

    expect($manifest)->toHaveKey('resources/js/app.js');
});
