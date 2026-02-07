<?php

namespace App\Livewire\Guestbook;

use App\Models\GuestbookEntry;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BonusPointsSidebar extends Component
{
    public int $eventConfigId;

    public function mount(int $eventConfigId): void
    {
        $this->eventConfigId = $eventConfigId;
    }

    #[Computed]
    public function electedOfficialCount(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)
            ->where('is_verified', true)
            ->count();
    }

    #[Computed]
    public function arrlOfficialCount(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL)
            ->where('is_verified', true)
            ->count();
    }

    #[Computed]
    public function agencyCount(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_AGENCY)
            ->where('is_verified', true)
            ->count();
    }

    #[Computed]
    public function mediaCount(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_MEDIA)
            ->where('is_verified', true)
            ->count();
    }

    #[Computed]
    public function totalBonusEligible(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('is_verified', true)
            ->bonusEligible()
            ->count();
    }

    #[Computed]
    public function bonusPoints(): int
    {
        return min($this->totalBonusEligible, 10) * 100;
    }

    #[Computed]
    public function progressPercentage(): int
    {
        return min(($this->totalBonusEligible / 10) * 100, 100);
    }

    #[Computed]
    public function isMaxBonusReached(): bool
    {
        return $this->totalBonusEligible >= 10;
    }

    public function render()
    {
        return view('livewire.guestbook.bonus-points-sidebar');
    }
}
