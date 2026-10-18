<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Unit\Policies;

use App\Models\Image;
use App\Models\User;
use App\Policies\ImagePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImagePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ImagePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ImagePolicy;
        Permission::create(['name' => 'manage-images']);
    }

    public function test_any_authenticated_user_can_view_images(): void
    {
        $user = User::factory()->create();

        expect($this->policy->viewAny($user))->toBeTrue();
    }

    public function test_any_authenticated_user_can_create_images(): void
    {
        $user = User::factory()->create();

        expect($this->policy->create($user))->toBeTrue();
    }

    public function test_user_can_delete_own_image(): void
    {
        $user = User::factory()->create();
        $image = Image::factory()->create(['uploaded_by_user_id' => $user->id]);

        expect($this->policy->delete($user, $image))->toBeTrue();
    }

    public function test_user_cannot_delete_others_image(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $image = Image::factory()->create(['uploaded_by_user_id' => $otherUser->id]);

        expect($this->policy->delete($user, $image))->toBeFalse();
    }

    public function test_user_with_manage_images_permission_can_delete_any_image(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-images');

        $otherUser = User::factory()->create();
        $image = Image::factory()->create(['uploaded_by_user_id' => $otherUser->id]);

        expect($this->policy->delete($admin, $image))->toBeTrue();
    }
}
