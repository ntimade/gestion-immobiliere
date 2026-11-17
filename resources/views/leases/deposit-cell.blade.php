{{-- La caution d'un bail dans une liste : elle reste visible une fois rendue ou retenue. --}}
@if ((float) $lease->deposit_amount > 0)
    {{ money($lease->deposit_amount) }}
    @if ((float) $lease->deposit_retained > 0)<span class="soft small cell-line">{{ __('retenue : :v1', ['v1' => money($lease->deposit_retained)]) }}</span>@endif
    @if ((float) $lease->deposit_refunded > 0)<span class="soft small cell-line">{{ __('rendue : :v1', ['v1' => money($lease->deposit_refunded)]) }}</span>@endif
    <span class="soft small cell-line">{{ __('encore détenue : :v1', ['v1' => money($lease->depositHeld())]) }}</span>
@else
    <span class="soft">—</span>
@endif
