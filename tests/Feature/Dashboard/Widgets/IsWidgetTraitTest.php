<?php

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Event;
use App\Models\EventType;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Concrete test widget that uses the IsWidget trait.
 *
 * Provides minimal implementations of the abstract methods
 * so we can test the trait's behavior in isolation.
 */
class TestWidget extends Component
{
    use IsWidget;

    public function getData(): array
    {
        return ['test_metric' => 42];
    }

    public function getWidgetListeners(): array
    {
        return ['contact-logged' => 'refresh'];
    }

    public function render(): View
    {
        return view('livewire.test-widget');
    }
}

beforeEach(function () {
    // Create a minimal Blade view for the test widget
    $viewPath = resource_path('views/livewire/test-widget.blade.php');
    if (! file_exists($viewPath)) {
        file_put_contents($viewPath, '<div>Test Widget {{ $widgetId }}</div>');
    }
});

afterAll(function () {
    $viewPath = resource_path('views/livewire/test-widget.blade.php');
    if (file_exists($viewPath)) {
        unlink($viewPath);
    }
});

test('trait compiles and can be used by a Livewire component', function () {
    Livewire::test(TestWidget::class, ['config' => [], 'size' => 'normal'])
        ->assertSuccessful();
});

test('mount initializes default properties', function () {
    Livewire::test(TestWidget::class, ['config' => [], 'size' => 'normal'])
        ->assertSet('size', 'normal')
        ->assertSet('config', []);
});

test('mount accepts custom config', function () {
    $config = ['metric' => 'total_contacts', 'refresh_interval' => 30];

    Livewire::test(TestWidget::class, ['config' => $config, 'size' => 'normal'])
        ->assertSet('config', $config);
});

test('mount accepts tv size variant', function () {
    Livewire::test(TestWidget::class, ['config' => [], 'size' => 'tv'])
        ->assertSet('size', 'tv');
});

test('mount accepts explicit widget ID', function () {
    Livewire::test(TestWidget::class, [
        'config' => [],
        'size' => 'normal',
        'widgetId' => 'my-custom-widget-id',
    ])
        ->assertSet('widgetId', 'my-custom-widget-id');
});

test('mount auto-generates widget ID when none provided', function () {
    $component = Livewire::test(TestWidget::class, ['config' => [], 'size' => 'normal']);

    $widgetId = $component->get('widgetId');

    expect($widgetId)
        ->toBeString()
        ->not->toBeEmpty()
        ->toStartWith('testwidget-');
});

test('auto-generated widget ID is deterministic for same config', function () {
    $config = ['metric' => 'total_score'];

    $id1 = Livewire::test(TestWidget::class, ['config' => $config, 'size' => 'normal'])
        ->get('widgetId');

    $id2 = Livewire::test(TestWidget::class, ['config' => $config, 'size' => 'normal'])
        ->get('widgetId');

    expect($id1)->toBe($id2);
});

test('auto-generated widget ID differs for different configs', function () {
    $id1 = Livewire::test(TestWidget::class, [
        'config' => ['metric' => 'total_contacts'],
        'size' => 'normal',
    ])->get('widgetId');

    $id2 = Livewire::test(TestWidget::class, [
        'config' => ['metric' => 'total_score'],
        'size' => 'normal',
    ])->get('widgetId');

    expect($id1)->not->toBe($id2);
});

test('refresh dispatches widget-updated event with widgetId', function () {
    Livewire::test(TestWidget::class, [
        'config' => [],
        'size' => 'normal',
        'widgetId' => 'stat-card-abc123',
    ])
        ->call('refresh')
        ->assertDispatched('widget-updated', widgetId: 'stat-card-abc123');
});

test('cache key contains class basename', function () {
    $component = Livewire::test(TestWidget::class, ['config' => [], 'size' => 'normal']);
    $cacheKey = $component->call('cacheKey')->get('__return');

    // Workaround: call directly on the component instance
    $instance = new TestWidget;
    $instance->config = [];
    $instance->widgetId = 'test';

    $cacheKey = $instance->cacheKey();

    expect($cacheKey)->toStartWith('dashboard:widget:TestWidget:');
});

test('cache key includes config hash', function () {
    $configA = ['metric' => 'total_contacts'];
    $configB = ['metric' => 'total_score'];

    $instanceA = new TestWidget;
    $instanceA->config = $configA;
    $instanceA->widgetId = 'test';

    $instanceB = new TestWidget;
    $instanceB->config = $configB;
    $instanceB->widgetId = 'test';

    expect($instanceA->cacheKey())->not->toBe($instanceB->cacheKey());
});

test('cache key includes no-event when no active event exists', function () {
    $instance = new TestWidget;
    $instance->config = [];
    $instance->widgetId = 'test';

    $cacheKey = $instance->cacheKey();

    expect($cacheKey)->toEndWith(':no-event');
});

test('cache key includes event ID when active event exists', function () {
    EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $instance = new TestWidget;
    $instance->config = [];
    $instance->widgetId = 'test';

    $cacheKey = $instance->cacheKey();

    expect($cacheKey)->toEndWith(":{$event->id}");
});

test('cache key is deterministic for same widget state', function () {
    $instance = new TestWidget;
    $instance->config = ['metric' => 'contacts'];
    $instance->widgetId = 'test';

    $key1 = $instance->cacheKey();
    $key2 = $instance->cacheKey();

    expect($key1)->toBe($key2);
});

test('cache key follows expected format', function () {
    $instance = new TestWidget;
    $instance->config = ['metric' => 'total'];
    $instance->widgetId = 'test';

    $cacheKey = $instance->cacheKey();

    expect($cacheKey)->toMatch('/^dashboard:widget:TestWidget:[a-f0-9]{32}:(no-event|\d+)$/');
});

test('shouldCache returns true by default', function () {
    $instance = new TestWidget;

    expect($instance->shouldCache())->toBeTrue();
});

test('isTvSize returns true for tv size', function () {
    Livewire::test(TestWidget::class, ['config' => [], 'size' => 'tv'])
        ->assertSet('size', 'tv');

    $instance = new TestWidget;
    $instance->size = 'tv';

    expect($instance->isTvSize())->toBeTrue();
});

test('isTvSize returns false for normal size', function () {
    $instance = new TestWidget;
    $instance->size = 'normal';

    expect($instance->isTvSize())->toBeFalse();
});

test('getData returns expected data from implementing class', function () {
    $instance = new TestWidget;

    expect($instance->getData())
        ->toBeArray()
        ->toHaveKey('test_metric', 42);
});

test('getWidgetListeners returns expected listeners from implementing class', function () {
    $instance = new TestWidget;

    expect($instance->getWidgetListeners())
        ->toBeArray()
        ->toHaveKey('contact-logged', 'refresh');
});
