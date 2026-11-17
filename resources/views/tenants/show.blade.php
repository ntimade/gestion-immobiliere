@extends('layouts.app')
@section('title', $tenant->name)

@section('content')
    <div class="page-head">
        <h1>{{ $tenant->name }}</h1>
        <div class="actions">
            <a class="btn ghost" href="{{ route('tenants.history', $tenant) }}">{{ __('Historique complet') }}</a>
            <a class="btn ghost" href="{{ route('tenants.edit', $tenant) }}">{{ __('Modifier') }}</a>
            <form class="inline" method="POST" action="{{ route('tenants.destroy', $tenant) }}" data-confirm="{{ __('Supprimer ce locataire ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <button class="btn danger">{{ __('Supprimer') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        <dl class="detail">
            <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $tenant->phone ?: '—' }}</dd></div>
            <div><dt>{{ __('E-mail') }}</dt><dd>{{ $tenant->email ?: '—' }}</dd></div>
            <div><dt>{{ __('N° de pièce d\'identité') }}</dt><dd>{{ $tenant->id_number ?: '—' }}</dd></div>
            <div><dt>{{ __('Reste à payer') }}</dt><dd @class(['danger-text' => $unpaid > 0])>{{ money($unpaid) }}</dd></div>
            <div><dt>{{ __('Caution versée') }}</dt><dd>{{ money($deposits->deposit) }}</dd></div>
            <div><dt>{{ __('Caution retenue') }}</dt><dd>{{ money($deposits->retained) }}</dd></div>
            <div><dt>{{ __('Caution rendue') }}</dt><dd>{{ money($deposits->refunded) }}</dd></div>
            <div><dt>{{ __('Caution encore détenue') }}</dt><dd><strong>{{ money($deposits->deposit - $deposits->retained - $deposits->refunded) }}</strong></dd></div>
        </dl>
    </div>

    <div class="card">
        <h2>{{ __('Logements occupés') }}</h2>
        @if ($leases->isEmpty())
            <p class="empty">{{ __('Ce locataire n\'a encore aucun logement. Enregistrez son entrée depuis la fiche d\'un logement libre.') }}</p>
            <a class="btn ghost small" href="{{ route('units.index', ['etat' => 'libres']) }}">{{ __('Voir les logements libres') }}</a>
        @else
            <div class="table-wrap"><table id="t-baux">
                <tr><th>{{ __('Logement') }}</th><x-sort-th :table="$leases" key="start" kind="date">{{ __('Entrée') }}</x-sort-th><x-sort-th :table="$leases" key="end" kind="date">{{ __('Sortie') }}</x-sort-th><x-sort-th :table="$leases" key="rent" kind="number" class="right">{{ __('Loyer') }}</x-sort-th><th>{{ __('Caution') }}</th><th></th></tr>
                @foreach ($leases as $lease)
                    <tr>
                        <td><a href="{{ route('leases.show', $lease) }}">{{ $lease->unit->label() }}</a></td>
                        <td>{{ $lease->start_date->format('d/m/Y') }}</td>
                        <td>
                            @if ($lease->end_date)
                                {{ $lease->end_date->format('d/m/Y') }}
                            @elseif ($lease->onNotice())
                                <span class="badge partial">{{ __('Préavis : départ le :v1', ['v1' => $lease->notice_end_date->format('d/m/Y')]) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="right">{{ money($lease->rent) }}</td>
                        <td>@include('leases.deposit-cell')</td>
                        <td>
                            <div class="actions">
                                <a class="btn small" href="{{ route('leases.show', $lease) }}">{{ __('Ouvrir le bail') }}</a>
                                @unless ($lease->end_date)
                                    {{-- Le préavis, l'état des lieux et la sortie se gèrent sur la fiche du bail. --}}
                                    <a class="btn ghost small" href="{{ route('leases.show', $lease) }}#preavis">{{ $lease->exitToFinalize() ? __('Finaliser la sortie') : __('Préavis / sortie') }}</a>
                                @endunless
                                <a class="btn ghost small" href="{{ route('leases.statement', $lease) }}">{{ __('État du bail') }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $leases->links() }}
        @endif
    </div>
@endsection
