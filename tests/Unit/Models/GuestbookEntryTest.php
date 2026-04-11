<?php

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
        expect($inPersonEntries->every(fn ($entry) => $entry->presence_type === GuestbookEntry::PRESENCE_TYPE_IN_PERSON))->toBeTrue();
    });

    test('it filters online entries with online scope', function () {
        // Create in-person entries
        GuestbookEntry::factory(2)->inPerson()->create();

        // Create online entries
        GuestbookEntry::factory(3)->online()->create();

        $onlineEntries = GuestbookEntry::online()->get();

        expect($onlineEntries)->toHaveCount(3);
        expect($onlineEntries->every(fn ($entry) => $entry->presence_type === GuestbookEntry::PRESENCE_TYPE_ONLINE))->toBeTrue();
    });

    test('it filters bonus-eligible entries with bonusEligible scope', function () {
        // Create bonus-eligible entries (each of the 3 categories)
        GuestbookEntry::factory()->electedOfficial()->create();
        GuestbookEntry::factory()->agency()->create();
        GuestbookEntry::factory()->media()->create();

        // Create non-bonus-eligible entries
        GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ARES_RACES,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_YOUTH,
        ]);
        GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        ]);

        $bonusEligibleEntries = GuestbookEntry::bonusEligible()->get();

        expect($bonusEligibleEntries)->toHaveCount(3);
        expect($bonusEligibleEntries->every(fn ($entry) => in_array($entry->visitor_category, GuestbookEntry::BONUS_ELIGIBLE_CATEGORIES)))->toBeTrue();
    });
});

describe('accessors', function () {
    test('it identifies bonus-eligible categories correctly', function () {
        $electedOfficial = GuestbookEntry::factory()->electedOfficial()->create();
        $agency = GuestbookEntry::factory()->agency()->create();
        $media = GuestbookEntry::factory()->media()->create();

        expect($electedOfficial->is_bonus_eligible)->toBeTrue();
        expect($agency->is_bonus_eligible)->toBeTrue();
        expect($media->is_bonus_eligible)->toBeTrue();
    });

    test('it identifies non-bonus categories correctly', function () {
        $aresRaces = GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ARES_RACES,
        ]);
        $hamClub = GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB,
        ]);
        $youth = GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_YOUTH,
        ]);
        $generalPublic = GuestbookEntry::factory()->create([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
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

describe('constants', function () {
    test('it has correct presence types defined', function () {
        expect(GuestbookEntry::PRESENCE_TYPES)->toBe([
            GuestbookEntry::PRESENCE_TYPE_IN_PERSON,
            GuestbookEntry::PRESENCE_TYPE_ONLINE,
        ]);

        expect(GuestbookEntry::PRESENCE_TYPE_IN_PERSON)->toBe('in_person');
        expect(GuestbookEntry::PRESENCE_TYPE_ONLINE)->toBe('online');
    });

    test('it has correct visitor categories defined', function () {
        expect(GuestbookEntry::VISITOR_CATEGORIES)->toBe([
            GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
            GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL,
            GuestbookEntry::VISITOR_CATEGORY_AGENCY,
            GuestbookEntry::VISITOR_CATEGORY_MEDIA,
            GuestbookEntry::VISITOR_CATEGORY_ARES_RACES,
            GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB,
            GuestbookEntry::VISITOR_CATEGORY_YOUTH,
            GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        ]);

        expect(GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)->toBe('elected_official');
        expect(GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL)->toBe('arrl_official');
        expect(GuestbookEntry::VISITOR_CATEGORY_AGENCY)->toBe('agency');
        expect(GuestbookEntry::VISITOR_CATEGORY_MEDIA)->toBe('media');
        expect(GuestbookEntry::VISITOR_CATEGORY_ARES_RACES)->toBe('ares_races');
        expect(GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB)->toBe('ham_club');
        expect(GuestbookEntry::VISITOR_CATEGORY_YOUTH)->toBe('youth');
        expect(GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)->toBe('general_public');
    });

    test('it has correct bonus-eligible categories defined', function () {
        expect(GuestbookEntry::BONUS_ELIGIBLE_CATEGORIES)->toBe([
            GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
            GuestbookEntry::VISITOR_CATEGORY_AGENCY,
            GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        ]);

        // Verify these 3 categories are the only bonus-eligible ones
        expect(GuestbookEntry::BONUS_ELIGIBLE_CATEGORIES)->toHaveCount(3);
    });
});
