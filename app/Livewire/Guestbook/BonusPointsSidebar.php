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
    public function arrlOfficialCount(): int
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL)
            ->where('is_verified', true)
            ->count();
    }

    /**
     * @return array<string, array{label: string, icon: string, iconColor: string, count: int, earned: bool, points: int, rule: string}>
     */
    #[Computed]
    public function bonusItems(): array
    {
        return [
            'elected_official' => [
                'label' => 'Elected Official Visit',
                'icon' => 'o-building-library',
                'iconColor' => 'text-primary',
                'count' => $this->electedOfficialCount,
                'earned' => $this->electedOfficialCount > 0,
                'points' => 100,
                'rule' => '7.3.11',
            ],
            'agency' => [
                'label' => 'Served Agency Visit',
                'icon' => 'o-shield-check',
                'iconColor' => 'text-info',
                'count' => $this->agencyCount,
                'earned' => $this->agencyCount > 0,
                'points' => 100,
                'rule' => '7.3.12',
            ],
            'media' => [
                'label' => 'Media Publicity',
                'icon' => 'o-tv',
                'iconColor' => 'text-secondary',
                'count' => $this->mediaCount,
                'earned' => $this->mediaCount > 0,
                'points' => 100,
                'rule' => '7.3.2',
            ],
        ];
    }

    #[Computed]
    public function totalBonusPoints(): int
    {
        return collect($this->bonusItems)->where('earned', true)->sum('points');
    }

    #[Computed]
    public function maxBonusPoints(): int
    {
        return collect($this->bonusItems)->sum('points');
    }

    public function render()
    {
        return view('livewire.guestbook.bonus-points-sidebar');
    }
}
