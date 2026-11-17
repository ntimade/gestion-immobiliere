{{-- Libre, occupé, ou occupé avec un préavis : la date à laquelle il se libère. Un meublé peut être en séjour à la nuit. --}}
@if (! $unit->currentLease && $unit->furnished && $unit->currentStay)
    <span class="badge partial">{{ __('En séjour · libre le :v1', ['v1' => $unit->currentStay->check_out_at->format('d/m/Y H:i')]) }}</span>
@elseif (! $unit->currentLease)
    <span class="badge off">{{ __('Libre') }}</span>
@elseif ($unit->currentLease->onNotice())
    <span class="badge partial">{{ __('Préavis · libre le :v1', ['v1' => $unit->currentLease->notice_end_date->copy()->addDay()->format('d/m/Y')]) }}</span>
@else
    <span class="badge ok">{{ __('Occupé') }}</span>
@endif
