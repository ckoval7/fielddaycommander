<?php

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;

uses()->group('unit', 'models');

describe('relationships', function () {
    test('it belongs to an event configuration', function () {
        $entry = GuestbookEntry::factory()->create();

        expect($entry->eventConfiguration)->toBeInstanceOf(EventConfiguration::class);
        expect($entry->eventConfiguration->id)->toBe($entry->event_configuration_id);
    });

    test('it belongs to a user when signed in', function () {
        $user = User::factory()->create();
        $entry = GuestbookEntry::factory()->create([
            'user_id' => $user->id,
        ]);

        expect($entry->user)->toBeInstanceOf(User::class);
        expect($entry->user->id)->toBe($user->id);
    });

    test('it can have no user for guest signees', function () {
        $entry = GuestbookEntry::factory()->create([
            'user_id' => null,
        ]);

        expect($entry->user_id)->toBeNull();
        expect($entry->user)->toBeNull();
    });

    test('it belongs to verifier when verified', function () {
        $verifier = User::factory()->create();
        $entry = GuestbookEntry::factory()->create([
            'is_verified' => true,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);

        expect($entry->verifiedBy)->toBeInstanceOf(User::class);
        expect($entry->verifiedBy->id)->toBe($verifier->id);
    });
});

describe('scopes', function () {
    test('it filters verified entries with verified scope', function () {
        // Create verified entries
        GuestbookEntry::factory(3)->verified()->create();

        // Create unverified entries
        GuestbookEntry::factory(2)->create(['is_verified' => false]);

        $verifiedEntries = GuestbookEntry::verified()->get();

        expect($verifiedEntries)->toHaveCount(3);
        expect($verifiedEntries->every(fn ($entry) => $entry->is_verified))->toBeTrue();
    });

    test('it filters unverified entries with unverified scope', function () {
        // Create verified entries
        GuestbookEntry::factory(2)->verified()->create();

        // Create unverified entries
        GuestbookEntry::factory(3)->create(['is_verified' => false]);

        $unverifiedEntries = GuestbookEntry::unverified()->get();

        expect($unverifiedEntries)->toHaveCount(3);
        expect($unverifiedEntries->every(fn ($entry) => ! $entry->is_verified))->toBeTrue();
    });

    test('it filters in-person entries with inPerson scope', function () {
        // Create in-person entries
        GuestbookEntry::factory(3)->inPerson()->create();

        // Create online entries
        GuestbookEntry::factory(2)->online()->create();

        $inPersonEntries = GuestbookEntry::inPerson()->get();

        expect($inPersonEntries)->toHaveCount(3);
        expect($inPersonEntries->every(fn ($entry) => $entry->presence_type === PresenceType::InPerson))->toBeTrue();
    });

    test('it filters online entries with online scope', function () {
        // Create in-person entries
        GuestbookEntry::factory(2)->inPerson()->create();

        // Create online entries
        GuestbookEntry::factory(3)->online()->create();

        $onlineEntries = GuestbookEntry::online()->get();

        expect($onlineEntries)->toHaveCount(3);
        expect($onlineEntries->every(fn ($entry) => $entry->presence_type === PresenceType::Online))->toBeTrue();
    });

    test('it filters bonus-eligible entries with bonusEligible scope', function () {
        GuestbookEntry::factory()->electedOfficial()->create();
        GuestbookEntry::factory()->agency()->create();

        // Media visits are tracked but claimed manually, so they are not bonus eligible here
        GuestbookEntry::factory()->media()->create();
        GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::AresRaces,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::HamClub,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::Youth,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::GeneralPublic,
        ]);

        $bonusEligibleEntries = GuestbookEntry::bonusEligible()->get();

        expect($bonusEligibleEntries)->toHaveCount(2);
        expect($bonusEligibleEntries->every(fn ($entry) => $entry->visitor_category->isBonusEligible()))->toBeTrue();
    });
});

describe('accessors', function () {
    test('it identifies bonus-eligible categories correctly', function () {
        $electedOfficial = GuestbookEntry::factory()->electedOfficial()->create();
        $agency = GuestbookEntry::factory()->agency()->create();
        $media = GuestbookEntry::factory()->media()->create();

        expect($electedOfficial->is_bonus_eligible)->toBeTrue();
        expect($agency->is_bonus_eligible)->toBeTrue();
        expect($media->is_bonus_eligible)->toBeFalse();
    });

    test('it identifies non-bonus categories correctly', function () {
        $aresRaces = GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::AresRaces,
        ]);
        $hamClub = GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::HamClub,
        ]);
        $youth = GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::Youth,
        ]);
        $generalPublic = GuestbookEntry::factory()->create([
            'visitor_category' => VisitorCategory::GeneralPublic,
        ]);

        expect($aresRaces->is_bonus_eligible)->toBeFalse();
        expect($hamClub->is_bonus_eligible)->toBeFalse();
        expect($youth->is_bonus_eligible)->toBeFalse();
        expect($generalPublic->is_bonus_eligible)->toBeFalse();
    });
});

describe('soft deletes', function () {
    test('it soft deletes entries', function () {
        $entry = GuestbookEntry::factory()->create();
        $entryId = $entry->id;

        $entry->delete();

        // Entry is soft deleted
        expect(GuestbookEntry::find($entryId))->toBeNull();

        // Entry still exists in database with deleted_at timestamp
        $deletedEntry = GuestbookEntry::withTrashed()->find($entryId);
        expect($deletedEntry)->not->toBeNull();
        expect($deletedEntry->deleted_at)->not->toBeNull();
    });

    test('it can restore soft deleted entries', function () {
        $entry = GuestbookEntry::factory()->create();
        $entryId = $entry->id;

        $entry->delete();
        expect(GuestbookEntry::find($entryId))->toBeNull();

        // Restore the entry
        $entry->restore();

        $restoredEntry = GuestbookEntry::find($entryId);
        expect($restoredEntry)->not->toBeNull();
        expect($restoredEntry->deleted_at)->toBeNull();
    });
});

describe('enums', function () {
    test('it casts presence type and visitor category to enums', function () {
        $entry = GuestbookEntry::factory()->inPerson()->electedOfficial()->create();

        expect($entry->fresh()->presence_type)->toBe(PresenceType::InPerson);
        expect($entry->fresh()->visitor_category)->toBe(VisitorCategory::ElectedOfficial);
    });

    test('only elected official and agency visits are bonus eligible', function () {
        expect(VisitorCategory::bonusEligible())->toBe([
            VisitorCategory::ElectedOfficial,
            VisitorCategory::Agency,
        ]);
    });

    test('every visitor category has a label option', function () {
        expect(VisitorCategory::options())->toHaveCount(count(VisitorCategory::cases()));
        expect(PresenceType::options())->toBe([
            ['value' => 'in_person', 'label' => 'In Person'],
            ['value' => 'online', 'label' => 'Online'],
        ]);
    });
});
