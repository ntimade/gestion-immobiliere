@switch($invoice->statusKey())
    @case('prepaid')<span class="badge ok">{{ __('Payé d\'avance') }}</span>@break
    @case('paid')<span class="badge ok">{{ __('Payé') }}</span>@break
    @case('late')<span class="badge ko">{{ __('En retard') }}</span>@break
    @case('partial')<span class="badge partial">{{ __('Payé en partie') }}</span>@break
    @default<span class="badge off">{{ __('À payer') }}</span>
@endswitch
