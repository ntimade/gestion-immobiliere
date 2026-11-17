@extends('layouts.app')
@section('title', __('Séjours'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Séjours à la nuit') }}</h1>
        <div class="actions">
            @if ($hasNightUnits)
                <a class="btn" href="{{ route('stays.create') }}"><x-icon name="bed" /> {{ __('Nouveau séjour') }}</a>
            @endif
        </div>
    </div>
    <p class="soft small">{{ __('Les logements meublés avec un tarif journalier se louent à la nuit, par tranches de 24 heures (une nuit minimum).') }}</p>

    @unless ($hasNightUnits)
        <div class="flash info">{{ __('Aucun logement ne se loue encore à la nuit. Ouvrez un logement meublé, cliquez sur Modifier et renseignez son tarif journalier.') }} <a href="{{ route('units.index') }}">{{ __('Voir les logements') }}</a></div>
    @endunless

    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label for="guest_id">{{ __('Client') }}</label>
                <select id="guest_id" name="guest_id">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($guests as $guest)
                        <option value="{{ $guest->id }}" @selected($filters['guest_id'] === $guest->id)>{{ $guest->name }}@if ($guest->phone) — {{ $guest->phone }}@endif</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="unit_id">{{ __('Logement') }}</label>
                <select id="unit_id" name="unit_id">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected($filters['unit_id'] === $unit->id)>{{ $unit->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="from">{{ __('Du') }}</label><input id="from" type="date" name="from" value="{{ $filters['from'] }}"></div>
            <div><label for="to">{{ __('Au') }}</label><input id="to" type="date" name="to" value="{{ $filters['to'] }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>

        <div class="row-between">
            <p>
                <strong>{{ __(':v1 séjour(s), :v2 jour(s)', ['v1' => $totals['count'], 'v2' => $totals['days']]) }}</strong>
                <span class="soft small cell-line">{{ __('Montant : :v1 · Payé : :v2 · Reste : :v3', ['v1' => money($totals['amount']), 'v2' => money($totals['paid']), 'v3' => money($totals['balance'])]) }}</span>
            </p>
            @if ($totals['count'] > 0)
                <div class="actions">
                    <a class="btn ghost small" href="{{ route('stays.report', request()->query()) }}"><x-icon name="receipt" /> {{ __('Imprimer') }}</a>
                    <a class="btn ghost small" href="{{ route('stays.report.pdf', request()->query()) }}" download><x-icon name="download" /> {{ __('PDF') }}</a>
                </div>
            @endif
        </div>

        @if ($stays->isEmpty())
            <p class="empty">{{ __('Aucun séjour.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-sejours">
                <tr><th>{{ __('Facture') }}</th><th>{{ __('Client') }}</th><th>{{ __('Logement') }}</th><x-sort-th :table="$stays" key="arrival" kind="date">{{ __('Arrivée') }}</x-sort-th><x-sort-th :table="$stays" key="departure" kind="date">{{ __('Libération') }}</x-sort-th><th>{{ __('Durée') }}</th><x-sort-th :table="$stays" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><x-sort-th :table="$stays" key="paid" kind="number" class="right">{{ __('Payé') }}</x-sort-th><th></th></tr>
                @foreach ($stays as $stay)
                    <tr>
                        <td><a href="{{ route('stays.show', $stay) }}">{{ $stay->number() }}</a></td>
                        <td>{{ $stay->guest->name }}@if ($stay->guest->phone)<span class="soft small cell-line">{{ $stay->guest->phone }}</span>@endif</td>
                        <td>{{ $stay->unit->label() }}</td>
                        <td>{{ $stay->check_in_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $stay->check_out_at->format('d/m/Y H:i') }}@if ($stay->inProgress())<span class="badge ok">{{ __('En cours') }}</span>@endif</td>
                        <td>{{ $stay->daysLabel() }}</td>
                        <td class="right">{{ money($stay->amount) }}</td>
                        <td class="right">{{ money($stay->paid_amount) }}@if ($stay->balance() > 0)<span class="danger-text small cell-line">{{ __('reste : :v1', ['v1' => money($stay->balance())]) }}</span>@endif</td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('stays.invoice', $stay) }}"><x-icon name="receipt" /> {{ __('Facture') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $stays->links() }}
        @endif
    </div>
@endsection
