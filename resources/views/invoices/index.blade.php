@extends('layouts.app')
@section('title', __('Factures'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Factures') }}</h1>
        <a class="btn ghost" href="{{ route('invoices.month', ['period' => $period]) }}">{{ __('Imprimer le mois') }}</a>
    </div>

    <form class="filters" method="GET">
        <div><label for="period">{{ __('Mois') }}</label><input id="period" type="month" name="period" value="{{ $period }}"></div>
        <button class="btn ghost">{{ __('Afficher') }}</button>
    </form>

    <div class="stats">
        <div class="stat"><span>{{ __('Facturé') }}</span><strong>{{ money($totals->billed) }}</strong></div>
        <div class="stat"><span>{{ __('Payé') }}</span><strong>{{ money($totals->paid) }}</strong></div>
    </div>

    <div class="card">
        @if ($invoices->isEmpty())
            <p class="empty">{{ __('Aucune facture pour ce mois.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-factures">
                <tr><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><x-sort-th :table="$invoices" key="paid" kind="number" class="right">{{ __('Payé') }}</x-sort-th><th>{{ __('État') }}</th><th></th></tr>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                        <td>{{ $invoice->lease->unit->label() }}</td>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td class="right">{{ money($invoice->amount) }}</td>
                        <td class="right">{{ money($invoice->paid_amount) }}</td>
                        <td>@include('invoices.status', ['invoice' => $invoice])</td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('invoices.show', $invoice) }}">{{ __('Voir') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $invoices->links() }}
        @endif
    </div>
@endsection
