<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('dashboards table exists', function () {
    expect(Schema::hasTable('dashboards'))->toBeTrue();
});

test('dashboards table has all required columns', function () {
    $columns = [
        'id',
        'user_id',
        'title',
        'config',
        'is_default',
        'layout_type',
        'description',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('dashboards', $column))->toBeTrue();
    }
});

test('can insert a dashboard record', function () {
    $user = User::factory()->create();

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'Test Dashboard',
        'config' => json_encode([]),
        'is_default' => false,
        'layout_type' => 'grid',
        'description' => 'A test dashboard',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('dashboards', [
        'user_id' => $user->id,
        'title' => 'Test Dashboard',
        'layout_type' => 'grid',
    ]);
});

test('is_default defaults to false', function () {
    $user = User::factory()->create();

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'Default Test',
        'config' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('dashboards', [
        'user_id' => $user->id,
        'title' => 'Default Test',
        'is_default' => false,
    ]);
});

test('layout_type defaults to grid', function () {
    $user = User::factory()->create();

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'Layout Test',
        'config' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('dashboards', [
        'user_id' => $user->id,
        'title' => 'Layout Test',
        'layout_type' => 'grid',
    ]);
});

test('description is nullable', function () {
    $user = User::factory()->create();

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'No Description',
        'config' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('dashboards', [
        'user_id' => $user->id,
        'title' => 'No Description',
        'description' => null,
    ]);
});

test('config column stores json data', function () {
    $user = User::factory()->create();
    $widgetConfig = [
        ['type' => 'stat_card', 'position' => ['x' => 0, 'y' => 0]],
        ['type' => 'chart', 'position' => ['x' => 1, 'y' => 0]],
    ];

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'JSON Test',
        'config' => json_encode($widgetConfig),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $record = DB::table('dashboards')
        ->where('user_id', $user->id)
        ->where('title', 'JSON Test')
        ->first();

    expect(json_decode($record->config, true))->toBe($widgetConfig);
});

test('cascades delete when user is deleted', function () {
    $user = User::factory()->create();

    DB::table('dashboards')->insert([
        'user_id' => $user->id,
        'title' => 'Cascade Test',
        'config' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('dashboards', [
        'user_id' => $user->id,
        'title' => 'Cascade Test',
    ]);

    $user->forceDelete();

    $this->assertDatabaseMissing('dashboards', [
        'user_id' => $user->id,
    ]);
});

test('foreign key prevents inserting with non-existent user_id', function () {
    expect(fn () => DB::table('dashboards')->insert([
        'user_id' => 999999,
        'title' => 'Bad FK',
        'config' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('composite index exists on user_id and is_default', function () {
    $indexes = collect(Schema::getIndexes('dashboards'));

    $compositeIndex = $indexes->firstWhere('name', 'dashboards_user_id_is_default_index');

    expect($compositeIndex)->not->toBeNull();
    expect($compositeIndex['columns'])->toBe(['user_id', 'is_default']);
});
