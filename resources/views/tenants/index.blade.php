@extends('layouts.app')
@section('title', __('Locataires'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Locataires') }}</h1>
        <a class="btn" href="{{ route('tenants.create') }}">{{ __('Ajouter un locataire') }}</a>
    </div>
    <div class="card">
        <form class="filters" method="GET">
            <div><label for="q">{{ __('Recherche') }}</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom ou téléphone') }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>
        @if ($tenants->isEmpty())
            <p class="empty">{{ __('Aucun locataire.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-locataires">
                <tr><x-sort-th :table="$tenants" key="name">{{ __('Nom') }}</x-sort-th><x-sort-th :table="$tenants" key="phone">{{ __('Téléphone') }}</x-sort-th><th>{{ __('Logement') }}</th><x-sort-th :table="$tenants" key="unpaid" kind="number" class="right">{{ __('Reste à payer') }}</x-sort-th><th>{{ __('Caution') }}</th></tr>
                @foreach ($tenants as $tenant)
                    <tr>
                        <td><a href="{{ route('tenants.show', $tenant) }}">{{ $tenant->name }}</a></td>
                        <td>{{ $tenant->phone }}@if ($tenant->phone && ! \App\Support\PhoneNumber::isInternational($tenant->phone)) <span class="badge partial">{{ __('À corriger') }}</span>@endif</td>
                        <td>
                            @forelse ($tenant->leases as $lease)
                                <a class="cell-line" href="{{ route('leases.show', $lease) }}">{{ $lease->unit->label() }}</a>
                            @empty
                                <span class="soft">—</span>
                            @endforelse
                        </td>
                        <td class="right">@if ($tenant->unpaid > 0)<span class="danger-text">{{ money($tenant->unpaid) }}</span>@else<span class="badge ok">{{ __('À jour') }}</span>@endif</td>
                        <td>
                            @if ((float) $tenant->deposit_total > 0)
                                {{ money($tenant->deposit_total) }}
                                @if ((float) $tenant->deposit_refunded > 0)<span class="soft small cell-line">{{ __('rendue : :v1', ['v1' => money($tenant->deposit_refunded)]) }}</span>@endif
                                <span class="soft small cell-line">{{ __('encore détenue : :v1', ['v1' => money($tenant->deposit_held)]) }}</span>
                            @else
                                <span class="soft">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $tenants->links() }}
        @endif
    </div>
@endsection
