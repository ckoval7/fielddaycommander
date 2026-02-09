<?php

use App\Livewire\Dashboard\DashboardEditor;
use App\Models\Dashboard;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('displays editable title and description fields in edit mode', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'My Dashboard',
        'description' => 'Dashboard description',
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->assertSee('Dashboard Name')
        ->assertSee('Description (optional)')
        ->assertSet('title', 'My Dashboard')
        ->assertSet('description', 'Dashboard description')
        ->assertSet('editMode', true);
});

it('can update dashboard title and description', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'description' => 'Original description',
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('title', 'Updated Title')
        ->set('description', 'Updated description')
        ->call('saveLayout')
        ->assertDispatched('dashboard-saved')
        ->assertDispatched('toast');

    $dashboard->refresh();
    expect($dashboard->title)->toBe('Updated Title');
    expect($dashboard->description)->toBe('Updated description');
});

it('validates that title is required', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('title', '')
        ->call('saveLayout')
        ->assertHasErrors(['title' => 'required']);
});

it('validates that title does not exceed max length', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('title', str_repeat('a', 256))
        ->call('saveLayout')
        ->assertHasErrors(['title' => 'max']);
});

it('validates that description does not exceed max length', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('description', str_repeat('a', 1001))
        ->call('saveLayout')
        ->assertHasErrors(['description' => 'max']);
});

it('restores original title and description when cancelling edit', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'description' => 'Original description',
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('title', 'Changed Title')
        ->set('description', 'Changed description')
        ->call('cancelEdit')
        ->assertSet('title', 'Original Title')
        ->assertSet('description', 'Original description')
        ->assertSet('editMode', false);

    // Database should not be updated
    $dashboard->refresh();
    expect($dashboard->title)->toBe('Original Title');
    expect($dashboard->description)->toBe('Original description');
});

it('can clear description by setting it to null', function () {
    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'My Dashboard',
        'description' => 'Some description',
        'config' => [],
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('description', '')
        ->call('saveLayout')
        ->assertDispatched('dashboard-saved');

    $dashboard->refresh();
    expect($dashboard->description)->toBeNull();
});

it('preserves widget configuration when updating title and description', function () {
    $widgets = [
        [
            'id' => 'widget-1',
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => 0,
            'visible' => true,
        ],
    ];

    $dashboard = Dashboard::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'config' => $widgets,
    ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->set('title', 'Updated Title')
        ->call('saveLayout');

    $dashboard->refresh();
    expect($dashboard->title)->toBe('Updated Title');
    expect($dashboard->config)->toEqual($widgets);
});
