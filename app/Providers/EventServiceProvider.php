<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginInfo;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use App\Scoring\DomainEvents\MessageChanged;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\DomainEvents\W1awBulletinChanged;
use App\Scoring\Listeners\ReconcileOnDomainEvent;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Login::class => [
            UpdateLastLoginInfo::class,
        ],
        MessageChanged::class => [
            ReconcileOnDomainEvent::class,
        ],
        GuestbookEntryChanged::class => [
            ReconcileOnDomainEvent::class,
        ],
        W1awBulletinChanged::class => [
            ReconcileOnDomainEvent::class,
        ],
        QsoLogged::class => [
            ReconcileOnDomainEvent::class,
        ],
    ];
}
