<?php

use App\Models\Contact;
use App\Models\User;
use App\Policies\ContactPolicy;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::findOrCreate('edit-contacts', 'web');

    $this->policy = new ContactPolicy;
    $this->contact = Contact::factory()->create();
});

describe('update', function () {
    it('allows users with edit-contacts permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-contacts');

        expect($this->policy->update($user, $this->contact))->toBeTrue();
    });

    it('denies users without edit-contacts permission', function () {
        $user = User::factory()->create();

        expect($this->policy->update($user, $this->contact))->toBeFalse();
    });
});

describe('delete', function () {
    it('allows users with edit-contacts permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-contacts');

        expect($this->policy->delete($user, $this->contact))->toBeTrue();
    });

    it('denies users without edit-contacts permission', function () {
        $user = User::factory()->create();

        expect($this->policy->delete($user, $this->contact))->toBeFalse();
    });
});

describe('restore', function () {
    it('allows users with edit-contacts permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-contacts');
        $this->contact->delete();

        expect($this->policy->restore($user, $this->contact))->toBeTrue();
    });

    it('denies users without edit-contacts permission', function () {
        $user = User::factory()->create();
        $this->contact->delete();

        expect($this->policy->restore($user, $this->contact))->toBeFalse();
    });
});
