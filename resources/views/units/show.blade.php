@extends('layouts.app')
@section('title', $unit->label())

@section('content')
    <div class="page-head">
        <h1>{{ $unit->label() }}</h1>
        <div class="actions">
            @if ($unit->rentsByNight())
                <a class="btn" href="{{ route('stays.create', ['unit' => $unit->id]) }}"><x-icon name="bed" /> {{ __('Nouveau séjour') }}</a>
            @endif
            @if ($unit->acceptsNewTenant())
                <a class="btn" href="{{ route('leases.create', $unit) }}">{{ __('Enregistrer une entrée') }}</a>
            @endif
            <a class="btn ghost" href="{{ route('units.edit', $unit) }}">{{ __('Modifier') }}</a>
            <form class="inline" method="POST" action="{{ route('units.destroy', $unit) }}" data-confirm="{{ __('Supprimer ce logement ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <button class="btn danger">{{ __('Supprimer') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        @include('units.detail-list', ['unit' => $unit])
    </div>

    @if ($unit->furnished && ($unit->rentsByNight() || $stays->isNotEmpty()))
        <div class="card">
            <div class="row-between">
                <h2>{{ __('Séjours à la nuit') }}</h2>
                @if ($stays->isNotEmpty())<a class="btn ghost small" href="{{ route('stays.index', ['unit_id' => $unit->id]) }}">{{ __('Tout l\'historique') }}</a>@endif
            </div>
            @if ($stays->isEmpty())
                <p class="empty">{{ __('Aucun séjour enregistré.') }}</p>
            @else
                <div class="table-wrap"><table id="t-sejours-logement">
                    <tr><th>{{ __('Facture') }}</th><th>{{ __('Client') }}</th><th>{{ __('Arrivée') }}</th><th>{{ __('Libération') }}</th><th class="right">{{ __('Montant') }}</th></tr>
                    @foreach ($stays as $stay)
                        <tr>
                            <td><a href="{{ route('stays.show', $stay) }}">{{ $stay->number() }}</a></td>
                            <td>{{ $stay->guest->name }}</td>
                            <td>{{ $stay->check_in_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $stay->check_out_at->format('d/m/Y H:i') }}</td>
                            <td class="right">{{ money($stay->amount) }}</td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Historique des locataires') }}</h2>
        @if ($leases->isEmpty())
            <p class="empty">{{ __('Aucun locataire enregistré.') }}</p>
        @else
            <div class="table-wrap"><table id="t-baux">
                <tr><th>{{ __('Locataire') }}</th><x-sort-th :table="$leases" key="start" kind="date">{{ __('Entrée') }}</x-sort-th><x-sort-th :table="$leases" key="end" kind="date">{{ __('Sortie') }}</x-sort-th><x-sort-th :table="$leases" key="rent" kind="number" class="right">{{ __('Loyer') }}</x-sort-th><th>{{ __('Caution') }}</th></tr>
                @foreach ($leases as $lease)
                    <tr>
                        <td><a href="{{ route('leases.show', $lease) }}">{{ $lease->tenant->name }}</a></td>
                        <td>{{ $lease->start_date->format('d/m/Y') }}</td>
                        <td>{{ $lease->end_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="right">{{ money($lease->rent) }}</td>
                        <td>@include('leases.deposit-cell')</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $leases->links() }}
        @endif
    </div>
@endsection
