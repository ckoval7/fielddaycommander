<?php

use App\Models\GuestbookEntry;
use App\Models\User;
use App\Policies\GuestbookEntryPolicy;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Ensure permissions exist
    Permission::findOrCreate('manage-guestbook', 'web');

    $this->policy = new GuestbookEntryPolicy;
    $this->entry = GuestbookEntry::factory()->create();
});

describe('viewAny', function () {
    it('allows users with manage-guestbook permission to view any', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->viewAny($user))->toBeTrue();
    });

    it('denies users without manage-guestbook permission', function () {
        $user = User::factory()->create();

        expect($this->policy->viewAny($user))->toBeFalse();
    });
});

describe('view', function () {
    it('allows users with manage-guestbook permission to view entry', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->view($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to view entry', function () {
        $user = User::factory()->create();

        expect($this->policy->view($user, $this->entry))->toBeFalse();
    });
});

describe('create', function () {
    it('allows authenticated users to create entries', function () {
        $user = User::factory()->create();

        expect($this->policy->create($user))->toBeTrue();
    });

    it('allows guests to create entries', function () {
        expect($this->policy->create(null))->toBeTrue();
    });
});

describe('update', function () {
    it('allows users with manage-guestbook permission to update', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->update($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to update', function () {
        $user = User::factory()->create();

        expect($this->policy->update($user, $this->entry))->toBeFalse();
    });
});

describe('delete', function () {
    it('allows users with manage-guestbook permission to delete', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->delete($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to delete', function () {
        $user = User::factory()->create();

        expect($this->policy->delete($user, $this->entry))->toBeFalse();
    });
});

describe('restore', function () {
    it('allows users with manage-guestbook permission to restore', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');
        $this->entry->delete();

        expect($this->policy->restore($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to restore', function () {
        $user = User::factory()->create();
        $this->entry->delete();

        expect($this->policy->restore($user, $this->entry))->toBeFalse();
    });
});

describe('forceDelete', function () {
    it('allows users with manage-guestbook permission to force delete', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->forceDelete($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to force delete', function () {
        $user = User::factory()->create();

        expect($this->policy->forceDelete($user, $this->entry))->toBeFalse();
    });
});

describe('verify', function () {
    it('allows users with manage-guestbook permission to verify', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-guestbook');

        expect($this->policy->verify($user, $this->entry))->toBeTrue();
    });

    it('denies users without manage-guestbook permission to verify', function () {
        $user = User::factory()->create();

        expect($this->policy->verify($user, $this->entry))->toBeFalse();
    });
});
