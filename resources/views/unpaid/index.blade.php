@extends('layouts.app')
@section('title', __('Impayés'))

@section('content')
    <div class="page-head"><h1>{{ __('Impayés') }}</h1></div>

    <div class="stats">
        <div @class(['stat', 'alert' => $overdueTotal > 0])><span>{{ __('Loyers en retard') }}</span><strong>{{ money($overdueTotal) }}</strong></div>
        <div class="stat"><span>{{ __('Loyers à venir, pas encore payés') }}</span><strong>{{ money($upcomingTotal) }}</strong></div>
    </div>

    <form class="filters" method="GET">
        <div>
            <label for="tenant_id">{{ __('Locataire') }}</label>
            <select id="tenant_id" name="tenant_id">
                <option value="">{{ __('Tous') }}</option>
                @foreach ($tenants as $tenant)
                    <option value="{{ $tenant->id }}" @selected($tenantId === $tenant->id)>{{ $tenant->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn ghost">{{ __('Filtrer') }}</button>
    </form>

    <div class="card">
        <h2>{{ __('Par locataire') }}</h2>
        @if ($leases->isEmpty())
            <p class="empty">{{ __('Aucun impayé.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-locataires">
                <tr><x-sort-th :table="$leases" key="tenant">{{ __('Locataire') }}</x-sort-th><th>{{ __('Logement') }}</th><x-sort-th :table="$leases" key="months" kind="number" class="right">{{ __('Mois dus') }}</x-sort-th><x-sort-th :table="$leases" key="overdue" kind="number" class="right">{{ __('En retard') }}</x-sort-th><x-sort-th :table="$leases" key="unpaid" kind="number" class="right">{{ __('Total impayé') }}</x-sort-th><th></th></tr>
                @foreach ($leases as $lease)
                    <tr>
                        <td><a href="{{ route('leases.show', $lease) }}">{{ $lease->tenant->name }}</a></td>
                        <td>{{ $lease->unit->label() }}</td>
                        <td class="right">{{ $lease->unpaid_months }}</td>
                        <td class="right @if ($lease->overdue_total > 0) danger-text @endif">{{ money($lease->overdue_total) }}</td>
                        <td class="right danger-text">{{ money($lease->unpaid_total) }}</td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('leases.statement', $lease) }}">{{ __('État du bail') }}</a><a class="btn ghost small" href="{{ route('leases.show', $lease) }}#encaisser">{{ __('Encaisser') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $leases->links() }}
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Détail par mois') }}</h2>
        @if ($invoices->isEmpty())
            <p class="empty">{{ __('Aucun impayé.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-impayes">
                <tr><x-sort-th :table="$invoices" key="tenant">{{ __('Locataire') }}</x-sort-th><x-sort-th :table="$invoices" key="unit">{{ __('Logement') }}</x-sort-th><x-sort-th :table="$invoices" key="period" kind="date">{{ __('Mois') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><x-sort-th :table="$invoices" key="paid" kind="number" class="right">{{ __('Payé') }}</x-sort-th><x-sort-th :table="$invoices" key="rest" kind="number" class="right">{{ __('Reste') }}</x-sort-th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><th></th></tr>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                        <td>{{ $invoice->lease->unit->label() }}</td>
                        <td>{{ $invoice->label() }}</td>
                        <td class="right">{{ money($invoice->amount) }}</td>
                        <td class="right">{{ money($invoice->paid_amount) }}</td>
                        <td class="right danger-text">{{ money($invoice->balance()) }}</td>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}@if ($invoice->isOverdue()) <span class="badge ko">{{ __('En retard') }}</span>@endif</td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('leases.show', $invoice->lease_id) }}#encaisser">{{ __('Encaisser') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $invoices->links() }}
        @endif
    </div>
@endsection
