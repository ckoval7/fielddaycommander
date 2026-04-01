<div>
    @can('verify-bonuses')
        @if($this->event->eventConfiguration && $this->eligibleBonusTypes->isNotEmpty())
            <div>Manual Bonus Claims</div>
            @foreach($this->eligibleBonusTypes as $bonusType)
                <div>{{ $bonusType->name }}</div>
            @endforeach
        @endif
    @endcan
</div>
