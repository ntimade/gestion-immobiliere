@extends('layouts.app')
@section('title', $property->name)

@if ($property->hasPosition())
    @push('head')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endpush
@endif

@section('content')
    <div class="page-head">
        <h1>{{ $property->name }}</h1>
        <div class="actions">
            <a class="btn ghost" href="{{ route('properties.edit', $property) }}">{{ __('Modifier') }}</a>
            <form class="inline" method="POST" action="{{ route('properties.destroy', $property) }}" data-confirm="{{ __('Supprimer ce bien ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <button class="btn danger">{{ __('Supprimer') }}</button>
            </form>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><span>{{ __('Revenu mensuel actuel') }}</span><strong>{{ money($figures['current']) }}</strong></div>
        <div class="stat"><span>{{ __('Revenu estimé si tout est loué') }}</span><strong>{{ money($figures['estimated']) }}</strong></div>
        <div @class(['stat', 'alert' => $figures['shortfall'] > 0])><span>{{ __('Manque à gagner par mois') }}</span><strong>{{ money($figures['shortfall']) }}</strong></div>
        <div class="stat"><span>{{ __('Taux d\'occupation') }}</span><strong>{{ $figures['occupancy'] }} %</strong></div>
    </div>

    <div class="card">
        <h2>{{ __('Détails du bien') }}</h2>
        <dl class="detail">
            <div><dt>{{ __('Type de bien') }}</dt><dd>{{ $property->kindLabel() }}</dd></div>
            <div><dt>{{ __('Ville') }}</dt><dd>{{ $property->city ?: '—' }}</dd></div>
            <div><dt>{{ __('Quartier') }}</dt><dd>{{ $property->neighbourhood ?: '—' }}</dd></div>
            <div><dt>{{ __('Adresse ou repère') }}</dt><dd>{{ $property->address ?: '—' }}</dd></div>
            <div><dt>{{ __('Logements') }}</dt><dd>{{ $figures['unitsCount'] }}</dd></div>
            <div><dt>{{ __('Occupés') }}</dt><dd>{{ $figures['occupied'] }}</dd></div>
            <div><dt>{{ __('Libres') }}</dt><dd>{{ $figures['vacant'] }}</dd></div>
            @if ($property->description)<div><dt>{{ __('Description') }}</dt><dd>{{ $property->description }}</dd></div>@endif
        </dl>

        @if ($figures['types']->isNotEmpty())
            <div class="table-wrap" style="margin-top: 16px"><table>
                <tr><th>{{ __('Configuration') }}</th><th class="right">{{ __('Nombre') }}</th><th class="right">{{ __('Occupés') }}</th><th class="right">{{ __('Libres') }}</th><th class="right">{{ __('Tarif') }}</th><th class="right">{{ __('Revenu actuel') }}</th><th class="right">{{ __('Revenu estimé') }}</th></tr>
                @foreach ($figures['types'] as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="right">{{ $row->total }}</td>
                        <td class="right">{{ $row->occupied }}</td>
                        <td class="right">{{ $row->total - $row->occupied }}</td>
                        <td class="right">{{ money($row->minRent) }}@if ($row->maxRent > $row->minRent) – {{ money($row->maxRent) }}@endif</td>
                        <td class="right">{{ money($row->current) }}</td>
                        <td class="right">{{ money($row->estimated) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <div class="row-between">
            <h2>{{ __('Localisation') }}</h2>
            @if ($property->hasPosition())
                <a class="btn ghost small" href="{{ $property->mapUrl() }}" target="_blank" rel="noopener"><x-icon name="map-pin" /> {{ __('Ouvrir l\'itinéraire') }}</a>
            @endif
        </div>
        @if ($property->hasPosition())
            <div id="property-map" style="height: 280px; border-radius: var(--radius-sm); isolation: isolate"></div>
        @else
            <p class="empty">{{ __('Ce bien n\'a pas encore de position sur la carte.') }}</p>
            <a class="btn ghost small" href="{{ route('properties.edit', $property) }}">{{ __('Ajouter sa position') }}</a>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Revenus') }}</h2>
        <dl class="detail">
            <div><dt>{{ __('Loyers :v1', ['v1' => of_period(now()->format('Y-m'))]) }}</dt><dd>{{ money($figures['billed']) }}</dd></div>
            <div><dt>{{ __('Encaissé ce mois-ci') }}</dt><dd>{{ money($figures['collected']) }}</dd></div>
            <div><dt>{{ __('Loyers en retard') }}</dt><dd @class(['danger-text' => $figures['overdue'] > 0])>{{ money($figures['overdue']) }}</dd></div>
            <div><dt>{{ __('Estimation sur 12 mois') }}</dt><dd>{{ money($figures['yearly']) }}</dd></div>
            <div><dt>{{ __('Cautions détenues') }}</dt><dd>{{ money($figures['deposits']) }}</dd></div>
        </dl>
        <p class="soft small">{{ __('L\'estimation sur 12 mois reprend les loyers actuels, sans compter les logements libres. « Encaissé ce mois-ci » compte les avances le mois où elles sont reçues. Les cautions ne sont pas des revenus.') }}</p>

        <div class="table-wrap cards-wide" style="margin-top: 12px"><table>
            <tr><th>{{ __('Mois') }}</th><th class="right">{{ __('Facturé') }}</th><th class="right">{{ __('Payé') }}</th><th class="right">{{ __('Reste') }}</th></tr>
            @foreach ($figures['history'] as $month)
                <tr>
                    <td>{{ period_label($month->period) }}</td>
                    <td class="right">{{ money($month->billed) }}</td>
                    <td class="right">{{ money($month->paid) }}</td>
                    <td class="right @if ($month->rest > 0) danger-text @endif">{{ money($month->rest) }}</td>
                </tr>
            @endforeach
        </table></div>
    </div>

    @php($singleUnit = $property->isSingle() ? $units->first() : null)
    <div class="card">
        @if ($singleUnit)
            <div class="row-between">
                <h2>{{ __('Détails du logement') }}</h2>
                <div class="actions">
                    @if ($singleUnit->acceptsNewTenant())
                        <a class="btn small" href="{{ route('leases.create', $singleUnit) }}">{{ __('Enregistrer une entrée') }}</a>
                    @endif
                    <a class="btn ghost small" href="{{ route('units.edit', $singleUnit) }}">{{ __('Modifier le logement') }}</a>
                </div>
            </div>
            @include('units.detail-list', ['unit' => $singleUnit])
        @else
            <div class="row-between">
                <h2>{{ __('Appartements et chambres') }}</h2>
                <a class="btn ghost small" href="{{ route('properties.edit', $property) }}#logements">{{ __('Ajouter ou modifier les logements') }}</a>
            </div>
            @if ($units->isEmpty())
                <p class="empty">{{ __('Aucun logement dans ce bien.') }}</p>
            @else
                @include('units.table', ['units' => $units, 'showProperty' => false])
            @endif
        @endif
    </div>
@endsection

@if ($property->hasPosition())
    @push('scripts')
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script>
            (function () {
                var position = [{{ (float) $property->latitude }}, {{ (float) $property->longitude }}];
                var map = L.map('property-map').setView(position, 16);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap'
                }).addTo(map);
                L.marker(position).addTo(map);
            })();
        </script>
    @endpush
@endif
