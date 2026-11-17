@extends('layouts.app')
@section('title', __('Abonnement'))

@section('content')
    <div class="page-head"><h1>{{ __('Abonnement') }}</h1></div>

    <div class="card">
        <h2>{{ __('Mon abonnement') }}</h2>
        @include('subscription.status', ['organisation' => $organisation])
    </div>

    @if ($pending)
        <div class="flash info">{{ __('Paiement en attente de validation : :v1, :v2, :v3.', ['v1' => \App\Models\SubscriptionPlan::labelFor($pending->months), 'v2' => $pending->amountLabel(), 'v3' => $pending->methodLabel()]) }}</div>
    @elseif (! auth()->user()->isAdmin())
        <div class="flash info">{{ __('Seul un administrateur de la société peut payer l\'abonnement. Contactez-le.') }}</div>
    @else
        <div class="card">
            <h2>{{ __('Choisir un plan') }}</h2>
            <form method="POST" action="{{ route('subscription.store') }}">
                @csrf
                <div class="plan-grid">
                    @foreach ($allPlans as $plan)
                        <label @class(['choice', 'plan-choice', 'plan-soon' => ! $plan->isAvailable()])>
                            <input type="radio" name="plan_id" value="{{ $plan->id }}" required @disabled(! $plan->isAvailable()) @checked($plan->isAvailable() && (int) old('plan_id', $plans->first()?->id) === $plan->id)>
                            <span><strong>{{ $plan->label() }}</strong><br>{{ $plan->isAvailable() ? $plan->priceLabel() : __('Prix à venir') }}</span>
                        </label>
                    @endforeach
                </div>
                @error('plan_id')<p class="danger-text small">{{ $message }}</p>@enderror

                @if ($plans->isEmpty())
                    <div class="flash info">{{ __('Les prix seront bientôt disponibles. Contactez le support pour vous abonner.') }}</div>
                @else
                <p class="plan-title"><strong>{{ __('Mode de paiement') }}</strong></p>
                <div class="choices plan-methods">
                    @foreach (\App\Models\SubscriptionPayment::METHODS as $method)
                        <label class="choice">
                            <input type="radio" name="method" value="{{ $method }}" required data-available="{{ in_array($method, \App\Models\SubscriptionPayment::AVAILABLE_METHODS, true) ? '1' : '0' }}" @checked(old('method', \App\Models\SubscriptionPayment::CASH) === $method)>
                            {{ \App\Models\SubscriptionPayment::labelForMethod($method) }}
                        </label>
                    @endforeach
                </div>
                @error('method')<div class="flash error plan-instructions">{{ $message }}</div>@enderror

                <div class="flash info plan-instructions" id="method-cash">{{ $cashInstructions ?: __('Remettez le montant au support. Votre abonnement sera activé à réception.') }}</div>

                <div class="form-actions"><button type="submit" class="btn">{{ __('Payer') }}</button></div>
                @endif
            </form>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Historique') }}</h2>
        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap"><table id="t-abonnements">
                <tr>
                    <x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="months" kind="number">{{ __('Plan') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="end" kind="date">{{ __('Échéance') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="status">{{ __('État') }}</x-sort-th>
                </tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->created_at->format('d/m/Y') }}</td>
                        <td>{{ \App\Models\SubscriptionPlan::labelFor($payment->months) }}</td>
                        <td class="right">{{ $payment->amountLabel() }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->period_end?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="badge {{ $payment->statusBadge() }}">{{ $payment->statusLabel() }}</span></td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var radios = document.querySelectorAll('input[name="method"]');
            if (!radios.length) {
                return;
            }

            // Orange et MTN se choisissent librement : le refus arrive au clic sur Payer.
            function refresh() {
                var chosen = document.querySelector('input[name="method"]:checked');
                document.getElementById('method-cash').hidden = !chosen || chosen.dataset.available !== '1';
            }

            radios.forEach(function (radio) { radio.addEventListener('change', refresh); });
            refresh();
        })();
    </script>
@endpush
