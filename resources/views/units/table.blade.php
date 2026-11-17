<div class="table-wrap cards-wide"><table id="t-logements">
    <tr>
        @if ($showProperty)<x-sort-th :table="$units" key="property">{{ __('Bien') }}</x-sort-th>@endif
        <x-sort-th :table="$units" key="number">{{ __('Numéro') }}</x-sort-th>
        <x-sort-th :table="$units" key="type">{{ __('Type') }}</x-sort-th>
        <th>{{ __('Détails') }}</th>
        <x-sort-th :table="$units" key="rent" kind="number" class="right">{{ __('Tarif') }}</x-sort-th>
        <th>{{ __('Statut') }}</th>
        <th>{{ __('Locataire') }}</th>
        <th></th>
    </tr>
    @foreach ($units as $unit)
        <tr>
            @if ($showProperty)<td><a href="{{ route('properties.show', $unit->property_id) }}">{{ $unit->property->name }}</a></td>@endif
            <td><a href="{{ route('units.show', $unit) }}">{{ $unit->number }}</a></td>
            <td>{{ $unit->typeLabel() }}</td>
            <td>{{ $unit->summary() ?: '—' }}</td>
            <td class="right">@if ((float) $unit->rent > 0){{ money($unit->rent) }}@else<span class="soft">{{ __('À la nuit') }}</span>@endif</td>
            <td>@include('units.status-badge')</td>
            <td>
                @if ($unit->currentLease)
                    <a href="{{ route('leases.show', $unit->currentLease) }}">{{ $unit->currentLease->tenant->name }}</a>
                @else
                    —
                @endif
            </td>
            <td>
                <div class="actions">
                    @if ($unit->acceptsNewTenant())
                        <a class="btn small" href="{{ route('leases.create', $unit) }}">{{ __('Enregistrer une entrée') }}</a>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
</table></div>
{{ $units->links() }}
