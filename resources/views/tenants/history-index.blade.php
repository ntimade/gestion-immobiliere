@extends('layouts.app')
@section('title', __('Historiques'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Historiques') }}</h1>
    </div>
    <p class="soft small">{{ __('Générez l\'historique complet d\'un locataire : tous ses baux, ses mois, ses versements et les mouvements de sa caution, même après sa sortie ou le remboursement de la caution.') }}</p>
    <div class="card">
        <form class="filters" method="GET">
            <div><label for="q">{{ __('Recherche') }}</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom ou téléphone') }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>
        @if ($tenants->isEmpty())
            <p class="empty">{{ __('Aucun locataire.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-historiques">
                <tr><x-sort-th :table="$tenants" key="name">{{ __('Locataire') }}</x-sort-th><th>{{ __('Baux') }}</th><x-sort-th :table="$tenants" key="paid" kind="number" class="right">{{ __('Total versé') }}</x-sort-th><x-sort-th :table="$tenants" key="unpaid" kind="number" class="right">{{ __('Total des impayés') }}</x-sort-th><th>{{ __('Caution') }}</th><th></th></tr>
                @foreach ($tenants as $tenant)
                    <tr>
                        <td><a href="{{ route('tenants.show', $tenant) }}">{{ $tenant->name }}</a>@if ($tenant->phone)<span class="soft small cell-line">{{ $tenant->phone }}</span>@endif</td>
                        <td>{{ $tenant->leases_count }}</td>
                        <td class="right">{{ money($tenant->paid_total) }}</td>
                        <td class="right">@if ($tenant->unpaid_total > 0)<span class="danger-text">{{ money($tenant->unpaid_total) }}</span>@else{{ money(0) }}@endif</td>
                        <td>
                            @if ((float) $tenant->deposit_total > 0)
                                {{ __('versée : :v1', ['v1' => money($tenant->deposit_total)]) }}
                                @if ((float) $tenant->deposit_retained > 0)<span class="soft small cell-line">{{ __('retenue : :v1', ['v1' => money($tenant->deposit_retained)]) }}</span>@endif
                                @if ((float) $tenant->deposit_refunded > 0)<span class="soft small cell-line">{{ __('rendue : :v1', ['v1' => money($tenant->deposit_refunded)]) }}</span>@endif
                                <span class="soft small cell-line">{{ __('encore détenue : :v1', ['v1' => money((float) $tenant->deposit_total - (float) $tenant->deposit_retained - (float) $tenant->deposit_refunded)]) }}</span>
                            @else
                                <span class="soft">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn small" href="{{ route('tenants.history', $tenant) }}"><x-icon name="clipboard-list" /> {{ __('Générer') }}</a>
                                <a class="btn ghost small" href="{{ route('tenants.history.pdf', $tenant) }}" download><x-icon name="receipt" /> {{ __('PDF') }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $tenants->links() }}
        @endif
    </div>
@endsection
