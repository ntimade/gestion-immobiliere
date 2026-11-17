@extends('layouts.support')
@section('title', __('Comptes'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Comptes') }}</h1>
        <span class="badge {{ $suspendedCount ? 'ko' : 'off' }}">{{ __(':v1 suspendu(s)', ['v1' => $suspendedCount]) }}</span>
    </div>

    <p class="subnav">
        <a href="{{ route('support.accounts.index', array_filter(['q' => $term])) }}" @if (! $suspendedOnly) aria-current="page" @endif>{{ __('Tous') }}</a>
        <a href="{{ route('support.accounts.index', array_filter(['q' => $term, 'etat' => 'suspendus'])) }}" @if ($suspendedOnly) aria-current="page" @endif>{{ __('Suspendus') }}</a>
    </p>

    <div class="card">
        <form class="filters" method="GET">
            @if ($suspendedOnly)<input type="hidden" name="etat" value="suspendus">@endif
            <div><label for="q">{{ __('Recherche') }}</label><input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Nom, code ou e-mail') }}"></div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>

        @if ($accounts->isEmpty())
            <p class="empty">{{ __('Aucun compte.') }}</p>
        @else
            <div class="table-wrap"><table id="t-comptes">
                <tr>
                    <x-sort-th :table="$accounts" key="name">{{ __('Organisation') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="code">{{ __('Code') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="owner">{{ __('Login') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="tenants" kind="number" class="right">{{ __('Locataires') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="units" kind="number" class="right">{{ __('Logements') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="invoices" kind="number" class="right">{{ __('Factures') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="created" kind="date">{{ __('Créé le') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="subscription" kind="date">{{ __('Échéance') }}</x-sort-th>
                    <x-sort-th :table="$accounts" key="status">{{ __('État') }}</x-sort-th>
                    <th></th>
                </tr>
                @foreach ($accounts as $account)
                    <tr>
                        <td class="wrap"><a href="{{ route('support.accounts.show', $account->id) }}">{{ $account->name }}</a></td>
                        <td>{{ $account->code }}</td>
                        <td class="wrap">{{ $account->owner_email ?? '—' }}</td>
                        <td class="right">{{ $account->tenants_total }}</td>
                        <td class="right">{{ $account->units_total }}</td>
                        <td class="right">{{ $account->invoices_total }}</td>
                        <td>{{ $account->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="badge {{ $account->subscriptionBadge() }}">{{ $account->subscribed_until?->format('d/m/Y') ?? ($account->trial_ends_at ? __('Essai : :v1', ['v1' => $account->trial_ends_at->format('d/m/Y')]) : __('Sans abonnement')) }}</span></td>
                        <td><span class="badge {{ $account->isSuspended() ? 'ko' : 'ok' }}">{{ $account->isSuspended() ? __('Suspendu') : __('Actif') }}</span></td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('support.accounts.show', $account->id) }}">{{ __('Ouvrir') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $accounts->links() }}
        @endif
    </div>
@endsection
