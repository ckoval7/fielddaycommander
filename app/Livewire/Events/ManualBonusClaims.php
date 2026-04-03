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

    /** @var array<int, int> */
    public array $quantities = [];

    public int $additionalYouth = 0;

    protected const MANUAL_BONUS_CODES = [
        'social_media',
        'public_location',
        'public_location_wfd',
        'public_info_booth',
        'educational_activity',
        'web_submission',
        'elected_official_visit',
        'agency_visit',
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

        $quantity = 1;
        $points = $bonusType->base_points;

        if ($bonusType->is_per_occurrence && $bonusType->max_occurrences) {
            $quantity = min(
                max((int) ($this->quantities[$bonusTypeId] ?? 1), 1),
                $bonusType->max_occurrences
            );
            $points = $quantity * $bonusType->base_points;
        }

        EventBonus::create([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $bonusTypeId,
            'claimed_by_user_id' => auth()->id(),
            'quantity' => $quantity,
            'calculated_points' => $points,
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

    /**
     * Get youth participation status computed from DB queries.
     *
     * @return array{auto_count: int, additional: int, total: int, points: int, max_points: int}|null
     */
    #[Computed]
    public function youthStatus(): ?array
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return null;
        }

        $bonusType = BonusType::where('code', 'youth_participation')
            ->where('event_type_id', $this->event->event_type_id)
            ->first();

        if (! $bonusType) {
            return null;
        }

        // Check class eligibility
        $classCode = $config->operatingClass?->code;
        $classes = $bonusType->eligible_classes;

        if ($classes !== null) {
            if (is_string($classes)) {
                $classes = json_decode($classes, true) ?? [];
            }
            if (! in_array($classCode, $classes)) {
                return null;
            }
        }

        $autoCount = $config->countYouthWithQsos();

        $bonus = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        $additional = $bonus ? (int) ($bonus->notes ?? 0) : 0;
        $this->additionalYouth = $additional;

        $total = min($autoCount + $additional, $bonusType->max_occurrences ?? 5);

        return [
            'auto_count' => $autoCount,
            'additional' => $additional,
            'total' => $total,
            'points' => $total * $bonusType->base_points,
            'max_points' => $bonusType->max_points ?? 100,
        ];
    }

    public function saveAdditionalYouth(): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        $bonusType = BonusType::where('code', 'youth_participation')->first();

        if (! $bonusType) {
            return;
        }

        $additional = max(0, $this->additionalYouth);
        $autoCount = $config->countYouthWithQsos();
        $total = min($autoCount + $additional, $bonusType->max_occurrences ?? 5);

        if ($total > 0) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $config->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => $total,
                    'calculated_points' => $total * $bonusType->base_points,
                    'notes' => $additional > 0 ? (string) $additional : null,
                    'is_verified' => true,
                    'verified_by_user_id' => auth()->id(),
                    'verified_at' => now(),
                ]
            );
        } else {
            EventBonus::where('event_configuration_id', $config->id)
                ->where('bonus_type_id', $bonusType->id)
                ->delete();
        }

        unset($this->youthStatus);
        $this->dispatch('bonus-claimed');
    }

    public function render(): View
    {
        return view('livewire.events.manual-bonus-claims');
    }
}
