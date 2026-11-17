@if ($payment->isPending())
    <div class="actions">
        <form class="inline" method="POST" action="{{ route('support.subscriptions.approve', $payment) }}" data-confirm="{{ __('Valider ce paiement et prolonger l\'abonnement ?') }}" onsubmit="return confirm(this.dataset.confirm)">
            @csrf
            <button class="btn small">{{ __('Valider') }}</button>
        </form>
        <form class="inline" method="POST" action="{{ route('support.subscriptions.reject', $payment) }}" data-confirm="{{ __('Refuser ce paiement ?') }}" onsubmit="return confirm(this.dataset.confirm)">
            @csrf
            <button class="btn danger small">{{ __('Refuser') }}</button>
        </form>
    </div>
@endif
