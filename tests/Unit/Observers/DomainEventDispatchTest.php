<?php

use App\Models\Contact;
use App\Models\Event as AppEvent;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Message;
use App\Models\W1awBulletin;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use App\Scoring\DomainEvents\MessageChanged;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\DomainEvents\W1awBulletinChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
    $this->event = AppEvent::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
});

it('message saves dispatch MessageChanged', function () {
    Event::fake([MessageChanged::class]);
    Message::factory()->create(['event_configuration_id' => $this->config->id]);
    Event::assertDispatched(MessageChanged::class);
});

it('w1aw saves dispatch W1awBulletinChanged', function () {
    Event::fake([W1awBulletinChanged::class]);
    W1awBulletin::factory()->create(['event_configuration_id' => $this->config->id]);
    Event::assertDispatched(W1awBulletinChanged::class);
});

it('guestbook saves dispatch GuestbookEntryChanged', function () {
    Event::fake([GuestbookEntryChanged::class]);
    GuestbookEntry::factory()->create(['event_configuration_id' => $this->config->id]);
    Event::assertDispatched(GuestbookEntryChanged::class);
});

it('contact saves dispatch QsoLogged', function () {
    Event::fake([QsoLogged::class]);
    Contact::factory()->create(['event_configuration_id' => $this->config->id]);
    Event::assertDispatched(QsoLogged::class);
});
