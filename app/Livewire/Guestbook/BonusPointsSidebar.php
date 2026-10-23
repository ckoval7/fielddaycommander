<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Guestbook;

use App\Enums\VisitorCategory;
use App\Models\EventConfiguration;
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

    /**
     * Verified entry counts keyed by visitor category value.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function verifiedCounts(): array
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfigId)
            ->verified()
            ->whereNot(fn ($query) => $query->online()->whereIn('visitor_category', VisitorCategory::inPersonVisitCategories()))
            ->toBase()
            ->selectRaw('visitor_category, COUNT(*) as aggregate')
            ->groupBy('visitor_category')
            ->pluck('aggregate', 'visitor_category')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function verifiedCount(VisitorCategory $category): int
    {
        return $this->verifiedCounts[$category->value] ?? 0;
    }

    #[Computed]
    public function electedOfficialCount(): int
    {
        return $this->verifiedCount(VisitorCategory::ElectedOfficial);
    }

    #[Computed]
    public function agencyCount(): int
    {
        return $this->verifiedCount(VisitorCategory::Agency);
    }

    #[Computed]
    public function mediaCount(): int
    {
        return $this->verifiedCount(VisitorCategory::Media);
    }

    #[Computed]
    public function arrlOfficialCount(): int
    {
        return $this->verifiedCount(VisitorCategory::ArrlOfficial);
    }

    /**
     * Visitor categories that earn a bonus under the event's rulebook.
     *
     * @return list<VisitorCategory>
     */
    #[Computed]
    public function bonusCategories(): array
    {
        return EventConfiguration::with('event')->find($this->eventConfigId)?->event?->guestbookBonusCategories() ?? [];
    }

    /**
     * @return array<string, array{label: string, icon: string, iconColor: string, count: int, earned: bool, points: int, rule: string}>
     */
    #[Computed]
    public function bonusItems(): array
    {
        $items = [
            'elected_official' => [
                'category' => VisitorCategory::ElectedOfficial,
                'label' => 'Elected Official Visit',
                'icon' => 'phosphor-buildings',
                'iconColor' => 'text-primary',
                'count' => $this->electedOfficialCount,
                'earned' => $this->electedOfficialCount > 0,
                'points' => 100,
                'rule' => '7.3.11',
            ],
            'agency' => [
                'category' => VisitorCategory::Agency,
                'label' => 'Served Agency Visit',
                'icon' => 'phosphor-shield-check',
                'iconColor' => 'text-info',
                'count' => $this->agencyCount,
                'earned' => $this->agencyCount > 0,
                'points' => 100,
                'rule' => '7.3.12',
            ],
        ];

        return collect($items)
            ->filter(fn (array $item) => in_array($item['category'], $this->bonusCategories, true))
            ->map(fn (array $item) => collect($item)->except('category')->all())
            ->all();
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
