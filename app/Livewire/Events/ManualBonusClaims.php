<?php

namespace App\Livewire\Events;

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManualBonusClaims extends Component
{
    public Event $event;

    /** @var array<int, string> */
    public array $notes = [];

    protected const MANUAL_BONUS_CODES = [
        'social_media',
        'public_location',
        'public_location_wfd',
    ];

    #[Computed]
    public function eligibleBonusTypes(): Collection
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return collect();
        }

        $classCode = $config->operatingClass?->code;

        return BonusType::query()
            ->where('is_active', true)
            ->where('event_type_id', $this->event->event_type_id)
            ->whereIn('code', self::MANUAL_BONUS_CODES)
            ->get()
            ->filter(function (BonusType $bonusType) use ($classCode) {
                $classes = $bonusType->eligible_classes;

                if ($classes === null) {
                    return true;
                }

                // BonusTypeSeeder double-encodes eligible_classes (json_encode + array cast)
                if (is_string($classes)) {
                    $classes = json_decode($classes, true) ?? [];
                }

                return in_array($classCode, $classes);
            });
    }

    #[Computed]
    public function claimedBonuses(): Collection
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return collect();
        }

        return EventBonus::where('event_configuration_id', $config->id)
            ->whereIn('bonus_type_id', $this->eligibleBonusTypes->pluck('id'))
            ->get()
            ->keyBy('bonus_type_id');
    }

    public function claim(int $bonusTypeId, ?string $notes = null): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        // Only allow manual bonus types
        $bonusType = $this->eligibleBonusTypes->firstWhere('id', $bonusTypeId);

        if (! $bonusType) {
            return;
        }

        // Prevent duplicate claims
        $exists = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusTypeId)
            ->exists();

        if ($exists) {
            return;
        }

        EventBonus::create([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $bonusTypeId,
            'claimed_by_user_id' => auth()->id(),
            'quantity' => 1,
            'calculated_points' => $bonusType->base_points,
            'notes' => $notes,
            'is_verified' => true,
            'verified_by_user_id' => auth()->id(),
            'verified_at' => now(),
        ]);

        unset($this->claimedBonuses);

        $this->dispatch('bonus-claimed');
    }

    public function unclaim(int $bonusTypeId): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        $bonusType = $this->eligibleBonusTypes->firstWhere('id', $bonusTypeId);

        if (! $bonusType) {
            return;
        }

        EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusTypeId)
            ->delete();

        unset($this->claimedBonuses);

        $this->dispatch('bonus-claimed');
    }

    public function render(): View
    {
        return view('livewire.events.manual-bonus-claims');
    }
}
