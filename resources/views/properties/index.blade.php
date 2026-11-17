@extends('layouts.app')
@section('title', __('Biens'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Biens') }}</h1>
        <a class="btn" href="{{ route('properties.create') }}">{{ __('Ajouter un bien') }}</a>
    </div>

    <div class="stats">
        <div class="stat"><span>{{ __('Revenu mensuel actuel') }}</span><strong>{{ money($totals['current']) }}</strong></div>
        <div class="stat"><span>{{ __('Revenu estimé si tout est loué') }}</span><strong>{{ money($totals['estimated']) }}</strong></div>
        <div @class(['stat', 'alert' => $totals['shortfall'] > 0])><span>{{ __('Manque à gagner par mois') }}</span><strong>{{ money($totals['shortfall']) }}</strong></div>
    </div>

    <div class="card">
        @if ($properties->isEmpty())
            <p class="empty">{{ __('Aucun bien.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-biens">
                <tr>
                    <x-sort-th :table="$properties" key="name">{{ __('Nom') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="kind">{{ __('Type') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="city">{{ __('Localisation') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="apartments" kind="number" class="right">{{ __('Appartements') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="rooms" kind="number" class="right">{{ __('Chambres') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="occupied" kind="number" class="right">{{ __('Occupés') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="current" kind="number" class="right">{{ __('Revenu actuel') }}</x-sort-th>
                    <x-sort-th :table="$properties" key="estimated" kind="number" class="right">{{ __('Revenu estimé') }}</x-sort-th>
                </tr>
                @foreach ($properties as $property)
                    <tr>
                        <td><a href="{{ route('properties.show', $property) }}">{{ $property->name }}</a></td>
                        <td>{{ $property->kindLabel() }}</td>
                        <td>{{ $property->place() ?: ($property->address ?: '—') }}</td>
                        <td class="right">{{ $property->apartments_count }}</td>
                        <td class="right">{{ $property->rooms_count }}</td>
                        <td class="right">{{ $property->occupied_count }} / {{ $property->units_count }}</td>
                        <td class="right">{{ money($property->current_income) }}</td>
                        <td class="right">{{ money($property->estimated_income) }}</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $properties->links() }}
        @endif
    </div>
@endsection
