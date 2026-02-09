<?php

use App\Livewire\Dashboard\DashboardManager;
use App\Models\Dashboard;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('component renders successfully', function () {
    Livewire::test(DashboardManager::class)
        ->assertOk()
        ->assertViewIs('livewire.dashboard.dashboard-manager');
});

test('loads user dashboards on mount', function () {
    Dashboard::factory()
        ->count(3)
        ->for($this->user)
        ->create();

    Livewire::test(DashboardManager::class)
        ->assertCount('dashboards', 3);
});

test('can open and close modal', function () {
    Livewire::test(DashboardManager::class)
        ->assertSet('showModal', false)
        ->call('openModal')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false);
});

test('opens modal when open-modal event is dispatched with dashboard-manager id', function () {
    Livewire::test(DashboardManager::class)
        ->assertSet('showModal', false)
        ->dispatch('open-modal', modalId: 'dashboard-manager')
        ->assertSet('showModal', true);
});

test('does not open modal when open-modal event is dispatched with different id', function () {
    Livewire::test(DashboardManager::class)
        ->assertSet('showModal', false)
        ->dispatch('open-modal', modalId: 'other-modal')
        ->assertSet('showModal', false);
});

test('can open and cancel create form', function () {
    Livewire::test(DashboardManager::class)
        ->assertSet('showCreateForm', false)
        ->call('openCreateForm')
        ->assertSet('showCreateForm', true)
        ->call('cancelCreate')
        ->assertSet('showCreateForm', false);
});

test('can create a blank dashboard', function () {
    Livewire::test(DashboardManager::class)
        ->set('newTitle', 'My Test Dashboard')
        ->set('newDescription', 'Test description')
        ->call('createDashboard')
        ->assertDispatched('toast')
        ->assertSet('showCreateForm', false);

    expect($this->user->dashboards()->count())->toBe(1);
    expect($this->user->dashboards()->first()->title)->toBe('My Test Dashboard');
    expect($this->user->dashboards()->first()->description)->toBe('Test description');
});

test('validates title is required when creating dashboard', function () {
    Livewire::test(DashboardManager::class)
        ->set('newTitle', '')
        ->call('createDashboard')
        ->assertHasErrors(['newTitle' => 'required']);
});

test('validates title max length when creating dashboard', function () {
    Livewire::test(DashboardManager::class)
        ->set('newTitle', str_repeat('a', 256))
        ->call('createDashboard')
        ->assertHasErrors(['newTitle' => 'max']);
});

test('can duplicate existing dashboard', function () {
    $dashboard = Dashboard::factory()
        ->for($this->user)
        ->create([
            'title' => 'Original Dashboard',
            'config' => [
                ['id' => 'widget-1', 'type' => 'stat_card', 'config' => [], 'order' => 0, 'visible' => true],
            ],
        ]);

    Livewire::test(DashboardManager::class)
        ->call('duplicateDashboard', $dashboard->id)
        ->assertDispatched('toast');

    expect($this->user->dashboards()->count())->toBe(2);
    $duplicated = $this->user->dashboards()
        ->where('id', '!=', $dashboard->id)
        ->first();
    expect($duplicated->title)->toBe('Original Dashboard (Copy)');
    expect($duplicated->config)->toBe($dashboard->config);
});

test('cannot duplicate dashboard owned by another user', function () {
    $otherUser = User::factory()->create();
    $dashboard = Dashboard::factory()
        ->for($otherUser)
        ->create();

    Livewire::test(DashboardManager::class)
        ->call('duplicateDashboard', $dashboard->id)
        ->assertDispatched('toast');

    expect($this->user->dashboards()->count())->toBe(0);
});

test('can set dashboard as default', function () {
    $dashboard1 = Dashboard::factory()
        ->for($this->user)
        ->create(['is_default' => true]);

    $dashboard2 = Dashboard::factory()
        ->for($this->user)
        ->create(['is_default' => false]);

    Livewire::test(DashboardManager::class)
        ->call('setDefault', $dashboard2->id)
        ->assertDispatched('toast');

    expect($dashboard1->fresh()->is_default)->toBeFalse();
    expect($dashboard2->fresh()->is_default)->toBeTrue();
});

test('cannot set default for dashboard owned by another user', function () {
    $otherUser = User::factory()->create();
    $dashboard = Dashboard::factory()
        ->for($otherUser)
        ->create();

    Livewire::test(DashboardManager::class)
        ->call('setDefault', $dashboard->id)
        ->assertDispatched('toast');

    expect($dashboard->fresh()->is_default)->toBeFalse();
});

test('can confirm and cancel delete', function () {
    $dashboard = Dashboard::factory()
        ->for($this->user)
        ->create();

    Livewire::test(DashboardManager::class)
        ->assertSet('showDeleteConfirmation', false)
        ->assertSet('dashboardToDelete', null)
        ->call('confirmDelete', $dashboard->id)
        ->assertSet('showDeleteConfirmation', true)
        ->assertSet('dashboardToDelete', $dashboard->id)
        ->call('cancelDelete')
        ->assertSet('showDeleteConfirmation', false)
        ->assertSet('dashboardToDelete', null);
});

test('can delete dashboard when user has multiple dashboards', function () {
    Dashboard::factory()
        ->for($this->user)
        ->create(['is_default' => true]);

    $dashboard2 = Dashboard::factory()
        ->for($this->user)
        ->create(['is_default' => false]);

    Livewire::test(DashboardManager::class)
        ->call('confirmDelete', $dashboard2->id)
        ->call('deleteDashboard')
        ->assertDispatched('toast');

    expect($this->user->dashboards()->count())->toBe(1);
    expect(Dashboard::find($dashboard2->id))->toBeNull();
});

test('cannot delete dashboard owned by another user', function () {
    $otherUser = User::factory()->create();
    Dashboard::factory()->for($otherUser)->create(['is_default' => true]);
    $dashboard = Dashboard::factory()
        ->for($otherUser)
        ->create(['is_default' => false]);

    Livewire::test(DashboardManager::class)
        ->call('confirmDelete', $dashboard->id)
        ->call('deleteDashboard')
        ->assertDispatched('toast');

    expect(Dashboard::find($dashboard->id))->not->toBeNull();
});

test('can copy dashboard from existing dashboard', function () {
    $existing = Dashboard::factory()
        ->for($this->user)
        ->create([
            'title' => 'Template',
            'description' => 'Template desc',
            'config' => [
                ['id' => 'widget-1', 'type' => 'stat_card', 'config' => [], 'order' => 0, 'visible' => true],
            ],
        ]);

    Livewire::test(DashboardManager::class)
        ->set('newTitle', 'My New Dashboard')
        ->set('copyFrom', $existing->id)
        ->call('createDashboard')
        ->assertDispatched('toast');

    expect($this->user->dashboards()->count())->toBe(2);
    $new = $this->user->dashboards()
        ->where('id', '!=', $existing->id)
        ->first();
    expect($new->title)->toBe('My New Dashboard');
    expect($new->config)->toBe($existing->config);
});

test('reset form clears all fields', function () {
    Livewire::test(DashboardManager::class)
        ->set('newTitle', 'Test')
        ->set('newDescription', 'Desc')
        ->set('copyFrom', 1)
        ->call('resetForm')
        ->assertSet('newTitle', '')
        ->assertSet('newDescription', '')
        ->assertSet('copyFrom', null);
});
