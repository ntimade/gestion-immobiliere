@extends('layouts.support')
@section('title', __('Abonnements'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Abonnements') }}</h1>
        <a class="btn ghost" href="{{ route('support.subscriptions.settings') }}">{{ __('Régler les plans') }}</a>
    </div>

    <p class="subnav">
        <a href="{{ route('support.subscriptions.index') }}" @if (! $all) aria-current="page" @endif>{{ __(':v1 en attente', ['v1' => $pendingCount]) }}</a>
        <a href="{{ route('support.subscriptions.index', ['etat' => 'tous']) }}" @if ($all) aria-current="page" @endif>{{ __('Tous') }}</a>
    </p>

    <p class="soft small">{{ __('Validez un paiement seulement après avoir reçu l\'argent : l\'abonnement est alors prolongé.') }}</p>

    <div class="card">
        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap"><table id="t-paiements">
                <tr>
                    <x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="organisation">{{ __('Organisation') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="months" kind="number">{{ __('Plan') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="end" kind="date">{{ __('Échéance') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="status">{{ __('État') }}</x-sort-th>
                    <th></th>
                </tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                        <td class="wrap"><a href="{{ route('support.accounts.show', $payment->organisation_id) }}">{{ $payment->organisation->name }}</a></td>
                        <td>{{ \App\Models\SubscriptionPlan::labelFor($payment->months) }}</td>
                        <td class="right">{{ $payment->amountLabel() }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->period_end?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="badge {{ $payment->statusBadge() }}">{{ $payment->statusLabel() }}</span></td>
                        <td>@include('support.subscriptions.actions', ['payment' => $payment])</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection
