@extends('layouts.app')
@section('title', __('Paiements'))

@section('content')
    <div class="page-head"><h1>{{ __('Historique des paiements') }}</h1></div>

    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label for="tenant_id">{{ __('Locataire') }}</label>
                <select id="tenant_id" name="tenant_id">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(request('tenant_id') == $tenant->id)>{{ $tenant->name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="from">{{ __('Du') }}</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
            <div><label for="to">{{ __('Au') }}</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>

        <p><strong>{{ __('Total : :v1', ['v1' => money($total)]) }}</strong></p>

        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-paiements">
                <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><x-sort-th :table="$payments" key="tenant">{{ __('Locataire') }}</x-sort-th><th>{{ __('Logement') }}</th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th><x-sort-th :table="$payments" key="reference">{{ __('Référence') }}</x-sort-th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                        <td><a href="{{ route('leases.show', $payment->lease_id) }}">{{ $payment->lease->tenant->name }}</a></td>
                        <td>{{ $payment->lease->unit->label() }}</td>
                        <td>{{ $payment->coveredLabel() }}</td>
                        <td>{{ $payment->method ?: '—' }}</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td class="right">{{ money($payment->amount) }}</td>
                        <td><div class="actions">@include('messages.payment-send', ['payment' => $payment, 'small' => true])</div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection
