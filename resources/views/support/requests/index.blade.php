@extends('layouts.support')
@section('title', __('Demandes'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Demandes de mot de passe') }}</h1>
        <span class="badge {{ $pendingCount ? 'partial' : 'off' }}">{{ __(':v1 en attente', ['v1' => $pendingCount]) }}</span>
    </div>

    <p class="subnav">
        <a href="{{ route('support.requests.index') }}" @if (! $all) aria-current="page" @endif>{{ __('En attente') }}</a>
        <a href="{{ route('support.requests.index', ['etat' => 'toutes']) }}" @if ($all) aria-current="page" @endif>{{ __('Toutes') }}</a>
    </p>

    <div class="card">
        @if ($requests->isEmpty())
            <p class="empty">{{ __('Aucune demande.') }}</p>
        @else
            <div class="table-wrap"><table id="t-demandes">
                <tr>
                    <x-sort-th :table="$requests" key="date" kind="date">{{ __('Date') }}</x-sort-th>
                    <x-sort-th :table="$requests" key="code">{{ __('Code organisation') }}</x-sort-th>
                    <x-sort-th :table="$requests" key="login">{{ __('Login') }}</x-sort-th>
                    <x-sort-th :table="$requests" key="contact">{{ __('Où joindre') }}</x-sort-th>
                    <th>{{ __('Compte') }}</th>
                    <x-sort-th :table="$requests" key="status">{{ __('État') }}</x-sort-th>
                    <th></th>
                </tr>
                @foreach ($requests as $item)
                    <tr>
                        <td>{{ $item->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $item->organisation_code }}</td>
                        <td class="wrap">{{ $item->login }}</td>
                        <td class="wrap">{{ $item->contact }}</td>
                        <td class="wrap">{{ $item->user?->organisation->name ?? __('Introuvable') }}</td>
                        <td><span class="badge {{ $item->statusBadge() }}">{{ $item->statusLabel() }}</span></td>
                        <td><div class="actions"><a class="btn ghost small" href="{{ route('support.requests.show', $item) }}">{{ __('Ouvrir') }}</a></div></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $requests->links() }}
        @endif
    </div>
@endsection
