<dl class="detail">
    <div><dt>{{ __('Type') }}</dt><dd>{{ $unit->typeLabel() }}</dd></div>
    <div><dt>{{ __('Montant du loyer') }}</dt><dd>@if ((float) $unit->rent > 0){{ money($unit->rent) }}@else{{ __('Pas de location au mois (à la nuit seulement)') }}@endif</dd></div>
    <div><dt>{{ __('État') }}</dt><dd>@include('units.status-badge')</dd></div>
    <div><dt>{{ __('Locataire') }}</dt><dd>@if ($unit->currentLease)<a href="{{ route('leases.show', $unit->currentLease) }}">{{ $unit->currentLease->tenant->name }}</a>@else — @endif</dd></div>
    <div><dt>{{ __('Surface') }}</dt><dd>{{ $unit->surface ? qty($unit->surface).' m²' : '—' }}</dd></div>
    <div><dt>{{ __('Étage') }}</dt><dd>{{ $unit->floor ?: '—' }}</dd></div>
    <div><dt>{{ __('Chambres') }}</dt><dd>{{ $unit->bedrooms ?? '—' }}</dd></div>
    <div><dt>{{ __('Salons') }}</dt><dd>{{ $unit->living_rooms ?? '—' }}</dd></div>
    <div><dt>{{ __('Cuisines') }}</dt><dd>{{ $unit->kitchens ?? '—' }}</dd></div>
    <div><dt>{{ __('Douches') }}</dt><dd>{{ $unit->bathrooms ?? '—' }}</dd></div>
    <div><dt>{{ __('Meublé') }}</dt><dd>{{ $unit->furnished ? __('Oui') : __('Non') }}</dd></div>
    @if ($unit->rentsByNight())<div><dt>{{ __('Tarif journalier (24 h)') }}</dt><dd>{{ money($unit->daily_rate) }}</dd></div>@endif
    <div><dt>{{ __('Équipements') }}</dt><dd>{{ implode(', ', $unit->amenityLabels()) ?: '—' }}</dd></div>
    @if ($unit->notes)<div><dt>{{ __('Observation') }}</dt><dd>{{ $unit->notes }}</dd></div>@endif
</dl>
